<?php

declare(strict_types=1);

namespace AiFunctionCaller;

use AiFunctionCaller\Attributes\Tool;
use AiFunctionCaller\Exceptions\AiFunctionCallerException;
use AiFunctionCaller\Hydrator\Hydrator;
use AiFunctionCaller\Providers\AnthropicProvider;
use AiFunctionCaller\Providers\GeminiProvider;
use AiFunctionCaller\Providers\OpenAiProvider;
use AiFunctionCaller\Schema\SchemaGenerator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

class FunctionCaller
{
    /** @var array<string, array{callable: callable, description: string, schema: array<string, mixed>, dtoClass: ?string}> */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> Registered DTO schemas */
    private array $registeredDtos = [];

    public function __construct(
        private readonly SchemaGenerator $schemaGenerator = new SchemaGenerator(),
        private readonly Hydrator $hydrator = new Hydrator(),
        private readonly OpenAiProvider $openAiProvider = new OpenAiProvider(),
        private readonly AnthropicProvider $anthropicProvider = new AnthropicProvider(),
        private readonly GeminiProvider $geminiProvider = new GeminiProvider()
    ) {
    }

    public static function create(): self
    {
        return new self();
    }

    public function getSchemaGenerator(): SchemaGenerator
    {
        return $this->schemaGenerator;
    }

    public function getHydrator(): Hydrator
    {
        return $this->hydrator;
    }

    /**
     * Register a tool with a callable handler.
     *
     * @param callable|array|string $callable
     * @param class-string|null $dtoClass Optional DTO class that represents the arguments
     */
    public function registerTool(
        string $name,
        callable|array|string $callable,
        ?string $description = null,
        ?string $dtoClass = null
    ): self {
        if ($dtoClass !== null && class_exists($dtoClass)) {
            $schema = $this->schemaGenerator->generate($dtoClass, strict: true);
        } else {
            $callableInfo = $this->schemaGenerator->generateForCallable($callable, $name, $description, strict: true);
            $schema = $callableInfo['parameters'];
            $description = $description ?? $callableInfo['description'];
        }

        $this->tools[$name] = [
            'callable' => is_callable($callable) ? $callable : \Closure::fromCallable($callable),
            'description' => $description ?? "Executes tool '$name'",
            'schema' => $schema,
            'dtoClass' => $dtoClass,
        ];

        return $this;
    }

    /**
     * Register all methods in a class marked with the #[Tool] attribute.
     *
     * @param class-string|object $classOrInstance
     */
    public function registerClass(string|object $classOrInstance): self
    {
        $refClass = new ReflectionClass($classOrInstance);
        $instance = is_object($classOrInstance) ? $classOrInstance : null;

        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $toolAttrs = $method->getAttributes(Tool::class);
            if (empty($toolAttrs)) {
                continue;
            }

            /** @var Tool $toolAttr */
            $toolAttr = $toolAttrs[0]->newInstance();
            $toolName = $toolAttr->name ?? $method->getName();
            $description = $toolAttr->description;

            // If instance is null and method is not static, instantiate class
            $target = $instance;
            if ($target === null && !$method->isStatic()) {
                $target = $refClass->newInstance();
            }

            $callable = $target !== null ? [$target, $method->getName()] : [$refClass->getName(), $method->getName()];
            $this->registerTool($toolName, $callable, $description);
        }

        return $this;
    }

    /**
     * Register a DTO class for Structured Outputs schema generation.
     *
     * @param class-string $dtoClass
     */
    public function registerDto(string $dtoClass): self
    {
        $schema = $this->schemaGenerator->generate($dtoClass, strict: true);
        $this->registeredDtos[$dtoClass] = $schema;
        return $this;
    }

    /**
     * Parse raw data or JSON string into a strongly typed DTO object.
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param array<string, mixed>|string $data
     * @return T
     */
    public function parse(string $dtoClass, array|string $data): object
    {
        return $this->hydrator->hydrate($dtoClass, $data);
    }

    /**
     * Parse array of raw data or JSON string into a list of DTO objects.
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param list<array<string, mixed>>|string $items
     * @return list<T>
     */
    public function parseCollection(string $dtoClass, array|string $items): array
    {
        return $this->hydrator->hydrateCollection($dtoClass, $items);
    }

    /**
     * Call a registered tool by name with arguments.
     *
     * @param array<string, mixed>|string $arguments JSON string or array of arguments
     */
    public function call(string $toolName, array|string $arguments): mixed
    {
        if (!isset($this->tools[$toolName])) {
            throw new AiFunctionCallerException("Tool '$toolName' is not registered.");
        }

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new AiFunctionCallerException("Arguments for '$toolName' must be a valid JSON object.");
            }
            $arguments = $decoded;
        }

        $tool = $this->tools[$toolName];
        $callable = $tool['callable'];
        $dtoClass = $tool['dtoClass'];

        // If explicit DTO class is provided
        if ($dtoClass !== null) {
            $dtoInstance = $this->hydrator->hydrate($dtoClass, $arguments);
            return $callable($dtoInstance);
        }

        // Check if callable expects a single DTO parameter
        $refFunc = $this->reflectCallable($callable);
        $params = $refFunc->getParameters();

        if (count($params) === 1) {
            $paramType = $params[0]->getType();
            if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
                $className = $paramType->getName();
                if (class_exists($className)) {
                    $dtoInstance = $this->hydrator->hydrate($className, $arguments);
                    return $callable($dtoInstance);
                }
            }
        }

        // Map arguments to parameter names
        $passedArgs = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $arguments)) {
                $passedArgs[] = $arguments[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $passedArgs[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $passedArgs[] = null;
            } else {
                throw new AiFunctionCallerException("Missing required argument '$name' for tool '$toolName'");
            }
        }

        return $callable(...$passedArgs);
    }

    /**
     * Handle OpenAI tool call format:
     * ['id' => 'call_xxx', 'function' => ['name' => 'xxx', 'arguments' => '{...}']]
     *
     * @param array{id: string, function: array{name: string, arguments: string|array<string, mixed>}} $toolCall
     * @return array{role: 'tool', tool_call_id: string, content: string}
     */
    public function handleOpenAiToolCall(array $toolCall): array
    {
        $callId = $toolCall['id'] ?? '';
        $name = $toolCall['function']['name'] ?? '';
        $arguments = $toolCall['function']['arguments'] ?? [];

        try {
            $result = $this->call($name, $arguments);
            return $this->openAiProvider->formatToolResult($callId, $result);
        } catch (\Throwable $e) {
            return $this->openAiProvider->formatToolResult($callId, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle Anthropic tool use block format:
     * ['id' => 'toolu_xxx', 'name' => 'xxx', 'input' => [...]]
     *
     * @param array{id: string, name: string, input: array<string, mixed>|string} $toolUse
     * @return array{type: 'tool_result', tool_use_id: string, content: string, is_error: bool}
     */
    public function handleAnthropicToolCall(array $toolUse): array
    {
        $toolUseId = $toolUse['id'] ?? '';
        $name = $toolUse['name'] ?? '';
        $input = $toolUse['input'] ?? [];

        try {
            $result = $this->call($name, $input);
            return $this->anthropicProvider->formatToolResult($toolUseId, $result, false);
        } catch (\Throwable $e) {
            return $this->anthropicProvider->formatToolResult($toolUseId, ['error' => $e->getMessage()], true);
        }
    }

    /**
     * Handle Google Gemini function call format:
     * ['name' => 'xxx', 'args' => [...]]
     *
     * @param array{name: string, args: array<string, mixed>|string} $functionCall
     * @return array{functionResponse: array{name: string, response: array<string, mixed>}}
     */
    public function handleGeminiToolCall(array $functionCall): array
    {
        $name = $functionCall['name'] ?? '';
        $args = $functionCall['args'] ?? [];

        try {
            $result = $this->call($name, $args);
            return $this->geminiProvider->formatToolResult($name, $result);
        } catch (\Throwable $e) {
            return $this->geminiProvider->formatToolResult($name, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get all registered tools in OpenAI format.
     *
     * @return list<array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>, strict: bool}}>
     */
    public function getOpenAiTools(bool $strict = true): array
    {
        $tools = [];
        foreach ($this->tools as $name => $tool) {
            $tools[] = $this->openAiProvider->formatTool(
                $name,
                $tool['description'],
                $tool['schema'],
                $strict
            );
        }
        return $tools;
    }

    /**
     * Get OpenAI Structured Outputs response_format for a DTO class.
     *
     * @param class-string $dtoClass
     * @return array{type: 'json_schema', json_schema: array{name: string, description: string, strict: bool, schema: array<string, mixed>}}
     */
    public function getOpenAiStructuredOutput(
        string $dtoClass,
        ?string $name = null,
        ?string $description = null
    ): array {
        $ref = new ReflectionClass($dtoClass);
        $name = $name ?? $ref->getShortName();
        $schema = $this->registeredDtos[$dtoClass] ?? $this->schemaGenerator->generate($dtoClass, strict: true);

        return $this->openAiProvider->formatStructuredOutput($name, $schema, $description, strict: true);
    }

    /**
     * Get all registered tools in Anthropic format.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function getAnthropicTools(): array
    {
        $tools = [];
        foreach ($this->tools as $name => $tool) {
            $tools[] = $this->anthropicProvider->formatTool(
                $name,
                $tool['description'],
                $tool['schema']
            );
        }
        return $tools;
    }

    /**
     * Get all registered tools in Gemini format.
     *
     * @return array{functionDeclarations: list<array{name: string, description: string, parameters: array<string, mixed>}>}
     */
    public function getGeminiTools(): array
    {
        $declarations = [];
        foreach ($this->tools as $name => $tool) {
            $declarations[] = $this->geminiProvider->formatTool(
                $name,
                $tool['description'],
                $tool['schema']
            );
        }
        return $this->geminiProvider->formatToolsDeclaration($declarations);
    }

    private function reflectCallable(callable $callable): \ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        if (is_object($callable) && !$callable instanceof \Closure) {
            return new ReflectionMethod($callable, '__invoke');
        }

        /** @var \Closure|string $callable */
        return new \ReflectionFunction($callable);
    }
}
