<?php

declare(strict_types=1);

namespace AiFunctionCaller\Schema;

use AiFunctionCaller\Attributes\Description;
use AiFunctionCaller\Attributes\Enum;
use AiFunctionCaller\Attributes\Field;
use AiFunctionCaller\Attributes\Items;
use AiFunctionCaller\Attributes\Max;
use AiFunctionCaller\Attributes\Min;
use AiFunctionCaller\Attributes\Optional;
use AiFunctionCaller\Attributes\Pattern;
use AiFunctionCaller\Attributes\StrictSchema;
use AiFunctionCaller\Attributes\Tool;
use AiFunctionCaller\Exceptions\SchemaException;
use BackedEnum;
use DateTimeInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

class SchemaGenerator
{
    /** @var array<string, array<string, mixed>> In-memory schema cache */
    private static array $cache = [];

    /**
     * Generate JSON Schema for a DTO class.
     *
     * @param class-string $class
     * @param bool $strict Whether to enforce OpenAI strict structured outputs (all fields required, additionalProperties: false)
     * @param list<string> $stack Internal stack for cycle detection
     * @return array<string, mixed>
     */
    public function generate(string $class, bool $strict = true, array $stack = []): array
    {
        $cacheKey = $class . ':' . ($strict ? 'strict' : 'loose');
        if (empty($stack) && isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        if (in_array($class, $stack, true)) {
            $cyclePath = implode(' -> ', array_merge($stack, [$class]));
            throw new SchemaException("Circular dependency detected in DTO hierarchy: {$cyclePath}", $class);
        }

        if (count($stack) >= 32) {
            throw new SchemaException("Maximum DTO nesting depth of 32 exceeded", $class);
        }

        $currentStack = array_merge($stack, [$class]);

        if (!class_exists($class) && !enum_exists($class)) {
            throw new SchemaException("Class or enum '$class' does not exist.", $class);
        }

        $refClass = new ReflectionClass($class);

        // Check if class has #[StrictSchema] attribute
        $strictAttr = $refClass->getAttributes(StrictSchema::class);
        if (!empty($strictAttr)) {
            $strict = $strictAttr[0]->newInstance()->strict;
        }

        if ($refClass->isEnum()) {
            $schema = $this->generateEnumSchema($refClass);
            if (empty($stack)) {
                self::$cache[$cacheKey] = $schema;
            }
            return $schema;
        }

        $schema = [
            'type' => 'object',
        ];

        $desc = $this->getClassDescription($refClass);
        if ($desc !== null) {
            $schema['description'] = $desc;
        }

        $properties = [];
        $required = [];

        // Check constructor parameters first (preferred for modern DTOs)
        $constructor = $refClass->getConstructor();
        $processedProps = [];

        if ($constructor !== null && $constructor->getNumberOfParameters() > 0) {
            foreach ($constructor->getParameters() as $param) {
                $propName = $param->getName();
                $processedProps[$propName] = true;

                $propSchema = $this->generateParameterSchema($param, $strict, $currentStack);
                $properties[$propName] = $propSchema;

                if ($this->isPropertyRequired($param, $strict)) {
                    $required[] = $propName;
                }
            }
        }

        // Also check any public properties not defined in constructor
        foreach ($refClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propName = $property->getName();
            if (isset($processedProps[$propName])) {
                continue;
            }

            $propSchema = $this->generatePropertySchema($property, $strict, $currentStack);
            $properties[$propName] = $propSchema;

            if ($this->isReflectionPropertyRequired($property, $strict)) {
                $required[] = $propName;
            }
        }

        $schema['properties'] = $properties;
        $schema['required'] = array_values(array_unique($required));

        if ($strict) {
            $schema['additionalProperties'] = false;
        }

        if (empty($stack)) {
            self::$cache[$cacheKey] = $schema;
        }
        return $schema;
    }

    /**
     * Generate tool parameter schema for a callable or method.
     *
     * @param callable|array|string $callable
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function generateForCallable(
        callable|array|string $callable,
        ?string $toolName = null,
        ?string $description = null,
        bool $strict = true
    ): array {
        $reflection = $this->reflectCallable($callable);

        // Tool attribute lookup
        $toolAttrs = $reflection->getAttributes(Tool::class);
        if (!empty($toolAttrs)) {
            $tool = $toolAttrs[0]->newInstance();
            $toolName = $toolName ?? $tool->name;
            $description = $description ?? $tool->description;
        }

        if ($toolName === null) {
            $toolName = $this->resolveCallableName($callable, $reflection);
        }

        if ($description === null) {
            $description = $this->getFunctionDescription($reflection);
        }

        // Check if the first and only parameter is a DTO class
        $params = $reflection->getParameters();
        if (count($params) === 1) {
            $paramType = $params[0]->getType();
            if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
                $className = $paramType->getName();
                if (class_exists($className)) {
                    $dtoSchema = $this->generate($className, $strict);
                    return [
                        'name' => $toolName,
                        'description' => $description ?? '',
                        'parameters' => $dtoSchema,
                    ];
                }
            }
        }

        // Generate schema from individual parameters
        $parametersSchema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        if ($strict) {
            $parametersSchema['additionalProperties'] = false;
        }

        foreach ($params as $param) {
            $pName = $param->getName();
            $parametersSchema['properties'][$pName] = $this->generateParameterSchema($param, $strict);

            if ($this->isPropertyRequired($param, $strict)) {
                $parametersSchema['required'][] = $pName;
            }
        }

        return [
            'name' => $toolName,
            'description' => $description ?? '',
            'parameters' => $parametersSchema,
        ];
    }

    /**
     * @param ReflectionClass<object> $refClass
     */
    private function generateEnumSchema(ReflectionClass $refClass): array
    {
        $schema = [
            'description' => $this->getClassDescription($refClass) ?? $refClass->getShortName(),
        ];

        if ($refClass->implementsInterface(BackedEnum::class)) {
            $enumClass = $refClass->getName();
            /** @var list<BackedEnum> $cases */
            $cases = $enumClass::cases();
            $values = [];
            $backingType = 'string';

            if (!empty($cases)) {
                $firstVal = $cases[0]->value;
                $backingType = is_int($firstVal) ? 'integer' : 'string';
                foreach ($cases as $case) {
                    $values[] = $case->value;
                }
            }

            $schema['type'] = $backingType;
            $schema['enum'] = $values;
        } else {
            // Unit enum
            $enumClass = $refClass->getName();
            /** @var list<UnitEnum> $cases */
            $cases = $enumClass::cases();
            $schema['type'] = 'string';
            $schema['enum'] = array_map(static fn(UnitEnum $c) => $c->name, $cases);
        }

        return $schema;
    }

    private function generateParameterSchema(ReflectionParameter $param, bool $strict, array $stack = []): array
    {
        $type = $param->getType();
        $schema = $this->typeToSchema($type, $strict, $param->getAttributes(Items::class), $stack);

        // Apply attributes
        $this->applyAttributes($schema, $param->getAttributes());

        // Docblock parameter description if available
        if (!isset($schema['description'])) {
            $docDesc = $this->extractParamDocDescription($param);
            if ($docDesc !== null) {
                $schema['description'] = $docDesc;
            }
        }

        // Handle default values
        if ($param->isDefaultValueAvailable()) {
            $schema['default'] = $param->getDefaultValue();
        }

        return $schema;
    }

    private function generatePropertySchema(ReflectionProperty $property, bool $strict, array $stack = []): array
    {
        $type = $property->getType();
        $schema = $this->typeToSchema($type, $strict, $property->getAttributes(Items::class), $stack);

        $this->applyAttributes($schema, $property->getAttributes());

        if (!isset($schema['description'])) {
            $docDesc = $this->extractPropertyDocDescription($property);
            if ($docDesc !== null) {
                $schema['description'] = $docDesc;
            }
        }

        if ($property->hasDefaultValue()) {
            $schema['default'] = $property->getDefaultValue();
        }

        return $schema;
    }

    /**
     * @param array<ReflectionAttribute<object>> $itemsAttrs
     * @param list<string> $stack
     */
    private function typeToSchema(?ReflectionType $type, bool $strict, array $itemsAttrs = [], array $stack = []): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof ReflectionUnionType) {
            $types = $type->getTypes();
            $isNullable = false;
            $nonNullTypes = [];

            foreach ($types as $t) {
                if ($t instanceof ReflectionNamedType && $t->getName() === 'null') {
                    $isNullable = true;
                } else {
                    $nonNullTypes[] = $t;
                }
            }

            if (count($nonNullTypes) === 1) {
                $baseSchema = $this->typeToSchema($nonNullTypes[0], $strict, $itemsAttrs, $stack);
                if ($isNullable) {
                    return $this->makeNullable($baseSchema, $strict);
                }
                return $baseSchema;
            }

            $schemas = array_map(fn($t) => $this->typeToSchema($t, $strict, $itemsAttrs, $stack), $nonNullTypes);
            if ($isNullable) {
                $schemas[] = ['type' => 'null'];
            }
            return ['anyOf' => $schemas];
        }

        if ($type instanceof ReflectionIntersectionType) {
            $schemas = array_map(fn($t) => $this->typeToSchema($t, $strict, $itemsAttrs, $stack), $type->getTypes());
            return ['allOf' => $schemas];
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            $isNullable = $type->allowsNull() && $typeName !== 'mixed';

            $baseSchema = match ($typeName) {
                'int' => ['type' => 'integer'],
                'float' => ['type' => 'number'],
                'string' => ['type' => 'string'],
                'bool' => ['type' => 'boolean'],
                'array' => $this->resolveArraySchema($itemsAttrs, $strict, $stack),
                'object' => ['type' => 'object'],
                'mixed' => [],
                default => $this->resolveNamedClassSchema($typeName, $strict, $stack),
            };

            if ($isNullable) {
                return $this->makeNullable($baseSchema, $strict);
            }

            return $baseSchema;
        }

        return [];
    }

    private function resolveNamedClassSchema(string $className, bool $strict, array $stack = []): array
    {
        if (is_subclass_of($className, DateTimeInterface::class) || $className === DateTimeInterface::class) {
            return [
                'type' => 'string',
                'format' => 'date-time',
                'description' => 'ISO 8601 date-time string (e.g. 2026-10-29T12:00:00Z)',
            ];
        }

        if (enum_exists($className)) {
            $refEnum = new ReflectionClass($className);
            return $this->generateEnumSchema($refEnum);
        }

        if (class_exists($className)) {
            return $this->generate($className, $strict, $stack);
        }

        return ['type' => 'string'];
    }

    /**
     * @param array<ReflectionAttribute<object>> $itemsAttrs
     * @param list<string> $stack
     */
    private function resolveArraySchema(array $itemsAttrs, bool $strict, array $stack = []): array
    {
        $schema = ['type' => 'array'];

        if (!empty($itemsAttrs)) {
            /** @var Items $items */
            $items = $itemsAttrs[0]->newInstance();
            $itemType = $items->type;

            if (class_exists($itemType) || enum_exists($itemType)) {
                $itemSchema = $this->resolveNamedClassSchema($itemType, $strict, $stack);
            } else {
                $itemSchema = match ($itemType) {
                    'int', 'integer' => ['type' => 'integer'],
                    'float', 'number' => ['type' => 'number'],
                    'string' => ['type' => 'string'],
                    'bool', 'boolean' => ['type' => 'boolean'],
                    default => ['type' => 'string'],
                };
            }

            if ($items->description !== null) {
                $itemSchema['description'] = $items->description;
            }

            $schema['items'] = $itemSchema;
        }

        return $schema;
    }

    private function makeNullable(array $schema, bool $strict): array
    {
        if (empty($schema)) {
            return $schema;
        }

        if (isset($schema['type'])) {
            if (is_string($schema['type'])) {
                $schema['type'] = [$schema['type'], 'null'];
                return $schema;
            }
            if (is_array($schema['type']) && !in_array('null', $schema['type'], true)) {
                $schema['type'][] = 'null';
                return $schema;
            }
        }

        if (isset($schema['anyOf'])) {
            $schema['anyOf'][] = ['type' => 'null'];
            return $schema;
        }

        return [
            'anyOf' => [
                $schema,
                ['type' => 'null'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<ReflectionAttribute<object>> $attributes
     */
    private function applyAttributes(array &$schema, array $attributes): void
    {
        foreach ($attributes as $attr) {
            $instance = $attr->newInstance();

            if ($instance instanceof Description) {
                $schema['description'] = $instance->description;
            } elseif ($instance instanceof Field) {
                if ($instance->description !== null) {
                    $schema['description'] = $instance->description;
                }
                if ($instance->title !== null) {
                    $schema['title'] = $instance->title;
                }
                if ($instance->default !== null) {
                    $schema['default'] = $instance->default;
                }
                if ($instance->format !== null) {
                    $schema['format'] = $instance->format;
                }
                foreach ($instance->extra as $k => $v) {
                    $schema[$k] = $v;
                }
            } elseif ($instance instanceof Min) {
                $type = $schema['type'] ?? null;
                if ($type === 'integer' || $type === 'number' || (is_array($type) && (in_array('integer', $type, true) || in_array('number', $type, true)))) {
                    $schema['minimum'] = $instance->value;
                } elseif ($type === 'string' || (is_array($type) && in_array('string', $type, true))) {
                    $schema['minLength'] = (int) $instance->value;
                } elseif ($type === 'array') {
                    $schema['minItems'] = (int) $instance->value;
                } else {
                    $schema['minimum'] = $instance->value;
                }
            } elseif ($instance instanceof Max) {
                $type = $schema['type'] ?? null;
                if ($type === 'integer' || $type === 'number' || (is_array($type) && (in_array('integer', $type, true) || in_array('number', $type, true)))) {
                    $schema['maximum'] = $instance->value;
                } elseif ($type === 'string' || (is_array($type) && in_array('string', $type, true))) {
                    $schema['maxLength'] = (int) $instance->value;
                } elseif ($type === 'array') {
                    $schema['maxItems'] = (int) $instance->value;
                } else {
                    $schema['maximum'] = $instance->value;
                }
            } elseif ($instance instanceof Pattern) {
                $schema['pattern'] = $instance->pattern;
            } elseif ($instance instanceof Enum) {
                $schema['enum'] = $instance->values;
            }
        }
    }

    private function isPropertyRequired(ReflectionParameter $param, bool $strict): bool
    {
        // Check for #[Optional] attribute
        if (!empty($param->getAttributes(Optional::class))) {
            return false;
        }

        // Check for #[Field(required: false)]
        $fieldAttrs = $param->getAttributes(Field::class);
        if (!empty($fieldAttrs)) {
            $field = $fieldAttrs[0]->newInstance();
            if ($field->required !== null) {
                return $field->required;
            }
        }

        // In strict mode (OpenAI), all properties are required unless explicitly opted out
        if ($strict) {
            return true;
        }

        return !$param->isOptional();
    }

    private function isReflectionPropertyRequired(ReflectionProperty $property, bool $strict): bool
    {
        if (!empty($property->getAttributes(Optional::class))) {
            return false;
        }

        $fieldAttrs = $property->getAttributes(Field::class);
        if (!empty($fieldAttrs)) {
            $field = $fieldAttrs[0]->newInstance();
            if ($field->required !== null) {
                return $field->required;
            }
        }

        if ($strict) {
            return true;
        }

        return !$property->hasDefaultValue();
    }

    /**
     * @param ReflectionClass<object> $refClass
     */
    private function getClassDescription(ReflectionClass $refClass): ?string
    {
        $descAttr = $refClass->getAttributes(Description::class);
        if (!empty($descAttr)) {
            return $descAttr[0]->newInstance()->description;
        }

        $doc = $refClass->getDocComment();
        if ($doc !== false) {
            return $this->parseSummaryFromDocBlock($doc);
        }

        return null;
    }

    private function getFunctionDescription(ReflectionFunctionAbstract $reflection): ?string
    {
        $descAttr = $reflection->getAttributes(Description::class);
        if (!empty($descAttr)) {
            return $descAttr[0]->newInstance()->description;
        }

        $doc = $reflection->getDocComment();
        if ($doc !== false) {
            return $this->parseSummaryFromDocBlock($doc);
        }

        return null;
    }

    private function parseSummaryFromDocBlock(string $doc): ?string
    {
        $lines = explode("\n", $doc);
        $cleanLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = ltrim($line, "/* \t");
            if (str_starts_with($line, '@')) {
                break; // Stop at annotations like @param or @return
            }
            if ($line !== '') {
                $cleanLines[] = $line;
            }
        }

        $summary = implode(' ', $cleanLines);
        return $summary !== '' ? $summary : null;
    }

    private function extractParamDocDescription(ReflectionParameter $param): ?string
    {
        $declaringFunc = $param->getDeclaringFunction();
        $doc = $declaringFunc->getDocComment();
        if ($doc === false) {
            return null;
        }

        $pName = preg_quote($param->getName(), '/');
        // Matches: @param type $paramName Description here
        if (preg_match('/@param\s+[^\$]*\$' . $pName . '\s+([^@\n\r]+)/', $doc, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractPropertyDocDescription(ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();
        if ($doc === false) {
            return null;
        }

        // Matches: @var type Description here
        if (preg_match('/@var\s+\S+\s+([^@\n\r]+)/', $doc, $matches)) {
            return trim($matches[1]);
        }

        return $this->parseSummaryFromDocBlock($doc);
    }

    private function reflectCallable(callable|array|string $callable): ReflectionFunctionAbstract
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            $parts = explode('::', $callable, 2);
            return new ReflectionMethod($parts[0], $parts[1]);
        }

        if (is_array($callable) && count($callable) === 2) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        if (is_object($callable) && !$callable instanceof \Closure) {
            return new ReflectionMethod($callable, '__invoke');
        }

        /** @var \Closure|string $callable */
        return new ReflectionFunction($callable);
    }

    private function resolveCallableName(callable|array|string $callable, ReflectionFunctionAbstract $reflection): string
    {
        if ($reflection instanceof ReflectionMethod) {
            return $reflection->getDeclaringClass()->getShortName() . '_' . $reflection->getName();
        }

        $name = $reflection->getName();
        return str_starts_with($name, '{closure') ? 'anonymous_function' : $name;
    }
}
