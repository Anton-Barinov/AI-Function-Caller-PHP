<?php

declare(strict_types=1);

namespace AiFunctionCaller\Providers;

class OpenAiProvider
{
    /**
     * Format a tool definition for OpenAI Chat Completions API.
     *
     * @param array<string, mixed> $parameters JSON Schema for parameters
     * @return array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>, strict: bool}}
     */
    public function formatTool(
        string $name,
        string $description,
        array $parameters,
        bool $strict = true
    ): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
                'strict' => $strict,
            ],
        ];
    }

    /**
     * Format response_format for OpenAI Structured Outputs.
     *
     * @param array<string, mixed> $schema
     * @return array{type: 'json_schema', json_schema: array{name: string, description: string, strict: bool, schema: array<string, mixed>}}
     */
    public function formatStructuredOutput(
        string $name,
        array $schema,
        ?string $description = null,
        bool $strict = true
    ): array {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $name,
                'description' => $description ?? 'Structured output schema for ' . $name,
                'strict' => $strict,
                'schema' => $schema,
            ],
        ];
    }

    /**
     * Format tool execution result to inject back into OpenAI messages array.
     *
     * @return array{role: 'tool', tool_call_id: string, content: string}
     */
    public function formatToolResult(string $toolCallId, mixed $result): array
    {
        $content = is_string($result)
            ? $result
            : (string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => $content,
        ];
    }
}
