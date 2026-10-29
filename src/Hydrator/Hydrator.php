<?php

declare(strict_types=1);

namespace AiFunctionCaller\Hydrator;

use AiFunctionCaller\Attributes\Enum;
use AiFunctionCaller\Attributes\Items;
use AiFunctionCaller\Attributes\Max;
use AiFunctionCaller\Attributes\Min;
use AiFunctionCaller\Attributes\Pattern;
use AiFunctionCaller\Exceptions\HydrationException;
use AiFunctionCaller\Exceptions\ValidationException;
use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

class Hydrator
{
    /**
     * Hydrate data (array or JSON string) into a strongly typed DTO class.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed>|string $data
     * @param int $depth Recursion depth tracker
     * @return T
     * @throws ValidationException
     * @throws HydrationException
     */
    public function hydrate(string $class, array|string $data, int $depth = 0): object
    {
        if ($depth >= 32) {
            throw new HydrationException("Maximum hydration depth of 32 exceeded (possible circular data structure)", $class);
        }

        if (is_string($data)) {
            try {
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new HydrationException("Failed to decode JSON: " . $e->getMessage(), $class, previous: $e);
            }

            if (!is_array($decoded)) {
                throw new HydrationException("JSON root must be an object/array to hydrate into '$class'", $class);
            }
            $data = $decoded;
        }

        if (!class_exists($class)) {
            throw new HydrationException("Class '$class' does not exist.", $class);
        }

        $refClass = new ReflectionClass($class);
        $constructor = $refClass->getConstructor();
        $errors = [];

        $args = [];
        $consumedKeys = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $pName = $param->getName();
                $consumedKeys[$pName] = true;

                if (!array_key_exists($pName, $data)) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[$pName] = $param->getDefaultValue();
                        continue;
                    }

                    if ($param->allowsNull()) {
                        $args[$pName] = null;
                        continue;
                    }

                    $errors[$pName][] = "Field is required and missing.";
                    continue;
                }

                $rawValue = $data[$pName];

                try {
                    $castedValue = $this->castValue(
                        $rawValue,
                        $param->getType(),
                        $param->getAttributes(),
                        $class,
                        $pName,
                        $depth
                    );

                    $this->validateConstraints($castedValue, $param->getAttributes(), $pName, $errors);
                    $args[$pName] = $castedValue;
                } catch (ValidationException $e) {
                    foreach ($e->getErrors() as $errPath => $errList) {
                        $fullPath = $pName . ($errPath !== '' ? '.' . $errPath : '');
                        $errors[$fullPath] = array_merge($errors[$fullPath] ?? [], $errList);
                    }
                } catch (\Throwable $e) {
                    $errors[$pName][] = $e->getMessage();
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException("Validation failed for $class", $errors);
        }

        try {
            $instance = $constructor !== null
                ? $refClass->newInstanceArgs($args)
                : $refClass->newInstance();
        } catch (\Throwable $e) {
            throw new HydrationException("Failed to instantiate $class: " . $e->getMessage(), $class, previous: $e);
        }

        // Hydrate any extra public properties that were not in constructor
        foreach ($refClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propName = $property->getName();
            if (isset($consumedKeys[$propName])) {
                continue;
            }

            if (array_key_exists($propName, $data)) {
                $rawValue = $data[$propName];
                try {
                    $castedValue = $this->castValue(
                        $rawValue,
                        $property->getType(),
                        $property->getAttributes(),
                        $class,
                        $propName,
                        $depth
                    );

                    $fieldErrors = [];
                    $this->validateConstraints($castedValue, $property->getAttributes(), $propName, $fieldErrors);
                    if (!empty($fieldErrors)) {
                        $errors = array_merge($errors, $fieldErrors);
                    } else {
                        $property->setValue($instance, $castedValue);
                    }
                } catch (\Throwable $e) {
                    $errors[$propName][] = $e->getMessage();
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException("Validation failed for $class", $errors);
        }

        return $instance;
    }

    /**
     * Hydrate an array of items into an array of DTOs.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param list<array<string, mixed>>|string $items
     * @return list<T>
     */
    public function hydrateCollection(string $class, array|string $items): array
    {
        if (is_string($items)) {
            $items = json_decode($items, true, 512, JSON_THROW_ON_ERROR);
        }

        if (!is_array($items)) {
            throw new HydrationException("Expected array collection for '$class'");
        }

        $result = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new HydrationException("Item at index $idx is not an array");
            }
            $result[] = $this->hydrate($class, $item);
        }

        return $result;
    }

    /**
     * Cast raw value to matching target type.
     *
     * @param array<\ReflectionAttribute<object>> $attributes
     */
    private function castValue(
        mixed $value,
        ?ReflectionType $type,
        array $attributes,
        string $declaringClass,
        string $propertyName,
        int $depth = 0
    ): mixed {
        if ($value === null) {
            if ($type !== null && !$type->allowsNull()) {
                throw new HydrationException("Value cannot be null", $declaringClass, $propertyName);
            }
            return null;
        }

        if ($type === null) {
            return $value;
        }

        // Union types
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                try {
                    return $this->castValue($value, $subType, $attributes, $declaringClass, $propertyName);
                } catch (\Throwable) {
                    continue;
                }
            }
            throw new HydrationException("Value does not match any type in union: " . (string)$type, $declaringClass, $propertyName);
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            switch ($typeName) {
                case 'int':
                    if (!is_numeric($value)) {
                        throw new HydrationException("Expected integer, got " . get_debug_type($value), $declaringClass, $propertyName);
                    }
                    return (int)$value;

                case 'float':
                    if (!is_numeric($value)) {
                        throw new HydrationException("Expected float/number, got " . get_debug_type($value), $declaringClass, $propertyName);
                    }
                    return (float)$value;

                case 'string':
                    if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                        return (string)$value;
                    }
                    throw new HydrationException("Expected string, got " . get_debug_type($value), $declaringClass, $propertyName);

                case 'bool':
                    if (is_bool($value)) {
                        return $value;
                    }
                    if ($value === 'true' || $value === 1 || $value === '1') {
                        return true;
                    }
                    if ($value === 'false' || $value === 0 || $value === '0') {
                        return false;
                    }
                    return (bool)$value;

                case 'array':
                    if (!is_array($value)) {
                        throw new HydrationException("Expected array, got " . get_debug_type($value), $declaringClass, $propertyName);
                    }
                    return $this->castArrayItems($value, $attributes, $declaringClass, $propertyName, $depth);

                case 'DateTimeImmutable':
                case DateTimeInterface::class:
                    if (!is_string($value)) {
                        throw new HydrationException("Expected date string, got " . get_debug_type($value), $declaringClass, $propertyName);
                    }
                    try {
                        return new DateTimeImmutable($value);
                    } catch (\Throwable $e) {
                        throw new HydrationException("Invalid date-time format '$value'", $declaringClass, $propertyName, previous: $e);
                    }

                case 'DateTime':
                    if (!is_string($value)) {
                        throw new HydrationException("Expected date string, got " . get_debug_type($value), $declaringClass, $propertyName);
                    }
                    try {
                        return new DateTime($value);
                    } catch (\Throwable $e) {
                        throw new HydrationException("Invalid date-time format '$value'", $declaringClass, $propertyName, previous: $e);
                    }

                default:
                    // Check enum
                    if (enum_exists($typeName)) {
                        return $this->castEnum($value, $typeName, $declaringClass, $propertyName);
                    }

                    // Check nested DTO class
                    if (class_exists($typeName)) {
                        if (!is_array($value)) {
                            throw new HydrationException("Expected array/object for nested class $typeName, got " . get_debug_type($value), $declaringClass, $propertyName);
                        }
                        /** @var class-string<object> $typeName */
                        return $this->hydrate($typeName, $value, $depth + 1);
                    }
            }
        }

        return $value;
    }

    /**
     * @param array<\ReflectionAttribute<object>> $attributes
     */
    private function castArrayItems(
        array $items,
        array $attributes,
        string $declaringClass,
        string $propertyName,
        int $depth = 0
    ): array {
        $itemType = null;
        foreach ($attributes as $attr) {
            if ($attr->getName() === Items::class) {
                /** @var Items $inst */
                $inst = $attr->newInstance();
                $itemType = $inst->type;
                break;
            }
        }

        if ($itemType === null) {
            return $items;
        }

        $result = [];
        foreach ($items as $index => $item) {
            $path = sprintf('%s[%d]', $propertyName, $index);

            if (class_exists($itemType)) {
                if (!is_array($item)) {
                    throw new HydrationException("Item at index $index must be an object/array", $declaringClass, $path);
                }
                /** @var class-string<object> $itemType */
                $result[] = $this->hydrate($itemType, $item, $depth + 1);
            } elseif (enum_exists($itemType)) {
                $result[] = $this->castEnum($item, $itemType, $declaringClass, $path);
            } else {
                $result[] = match ($itemType) {
                    'int', 'integer' => (int)$item,
                    'float', 'number' => (float)$item,
                    'string' => (string)$item,
                    'bool', 'boolean' => (bool)$item,
                    default => $item,
                };
            }
        }

        return $result;
    }

    private function castEnum(
        mixed $value,
        string $enumClass,
        string $declaringClass,
        string $propertyName
    ): object {
        $refClass = new ReflectionClass($enumClass);

        if ($refClass->implementsInterface(BackedEnum::class)) {
            /** @var class-string<BackedEnum> $enumClass */
            $enumInstance = $enumClass::tryFrom($value);
            if ($enumInstance === null) {
                $cases = array_map(static fn(BackedEnum $c) => (string)$c->value, $enumClass::cases());
                throw new HydrationException(
                    sprintf("Invalid value '%s' for enum %s. Allowed: [%s]", (string)$value, $enumClass, implode(', ', $cases)),
                    $declaringClass,
                    $propertyName
                );
            }
            return $enumInstance;
        }

        if ($refClass->implementsInterface(UnitEnum::class)) {
            /** @var class-string<UnitEnum> $enumClass */
            foreach ($enumClass::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }
            throw new HydrationException(
                sprintf("Invalid name '%s' for unit enum %s", (string)$value, $enumClass),
                $declaringClass,
                $propertyName
            );
        }

        throw new HydrationException("'$enumClass' is not a valid enum", $declaringClass, $propertyName);
    }

    /**
     * @param array<\ReflectionAttribute<object>> $attributes
     * @param array<string, list<string>> $errors
     */
    private function validateConstraints(
        mixed $value,
        array $attributes,
        string $propertyName,
        array &$errors
    ): void {
        if ($value === null) {
            return;
        }

        foreach ($attributes as $attr) {
            $instance = $attr->newInstance();

            if ($instance instanceof Min) {
                if (is_int($value) || is_float($value)) {
                    if ($value < $instance->value) {
                        $errors[$propertyName][] = sprintf("Value %s is less than minimum %s", $value, $instance->value);
                    }
                } elseif (is_string($value)) {
                    if (mb_strlen($value) < $instance->value) {
                        $errors[$propertyName][] = sprintf("Length %d is less than minLength %d", mb_strlen($value), (int)$instance->value);
                    }
                } elseif (is_array($value)) {
                    if (count($value) < $instance->value) {
                        $errors[$propertyName][] = sprintf("Item count %d is less than minItems %d", count($value), (int)$instance->value);
                    }
                }
            }

            if ($instance instanceof Max) {
                if (is_int($value) || is_float($value)) {
                    if ($value > $instance->value) {
                        $errors[$propertyName][] = sprintf("Value %s is greater than maximum %s", $value, $instance->value);
                    }
                } elseif (is_string($value)) {
                    if (mb_strlen($value) > $instance->value) {
                        $errors[$propertyName][] = sprintf("Length %d is greater than maxLength %d", mb_strlen($value), (int)$instance->value);
                    }
                } elseif (is_array($value)) {
                    if (count($value) > $instance->value) {
                        $errors[$propertyName][] = sprintf("Item count %d is greater than maxItems %d", count($value), (int)$instance->value);
                    }
                }
            }

            if ($instance instanceof Pattern) {
                if (is_string($value) && !preg_match($instance->pattern, $value)) {
                    $errors[$propertyName][] = sprintf("Value does not match required pattern %s", $instance->pattern);
                }
            }

            if ($instance instanceof Enum) {
                if (!in_array($value, $instance->values, true)) {
                    $errors[$propertyName][] = sprintf("Value '%s' is not in allowed list [%s]", (string)$value, implode(', ', $instance->values));
                }
            }
        }
    }
}
