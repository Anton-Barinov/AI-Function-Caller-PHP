<?php

declare(strict_types=1);

namespace AiFunctionCaller\Providers;

class AnthropicProvider
{
    /**
     * Format a tool definition for Anthropic Messages API.
     *
     * @param array<string, mixed> $parameters JSON Schema for input parameters
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function formatTool(
        string $name,
        string $description,
        array $parameters
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => $parameters,
        ];
    }

    /**
     * Format a tool result block for Anthropic Messages API.
     *
     * @return array{type: 'tool_result', tool_use_id: string, content: string, is_error: bool}
     */
    public function formatToolResult(string $toolUseId, mixed $result, bool $isError = false): array
    {
        $content = is_string($result)
            ? $result
            : (string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $content,
            'is_error' => $isError,
        ];
    }
}
