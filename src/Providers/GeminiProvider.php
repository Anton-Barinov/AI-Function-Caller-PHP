<?php

declare(strict_types=1);

namespace AiFunctionCaller\Providers;

class GeminiProvider
{
    /**
     * Format a tool definition for Google Gemini API functionDeclarations.
     *
     * @param array<string, mixed> $parameters
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function formatTool(
        string $name,
        string $description,
        array $parameters
    ): array {
        // Gemini expects OpenAPI 3.0 type formats (STRING, INTEGER, NUMBER, BOOLEAN, OBJECT, ARRAY)
        // Convert schema types to uppercase if needed or pass valid OpenAPI 3.0 schema
        $adaptedParameters = $this->adaptSchemaForGemini($parameters);

        return [
            'name' => $name,
            'description' => $description,
            'parameters' => $adaptedParameters,
        ];
    }

    /**
     * Wrap multiple tool declarations into Gemini tools payload.
     *
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $functionDeclarations
     * @return array{functionDeclarations: list<array{name: string, description: string, parameters: array<string, mixed>}>}
     */
    public function formatToolsDeclaration(array $functionDeclarations): array
    {
        return [
            'functionDeclarations' => $functionDeclarations,
        ];
    }

    /**
     * Format tool response for Gemini content parts.
     *
     * @return array{functionResponse: array{name: string, response: array<string, mixed>}}
     */
    public function formatToolResult(string $functionName, mixed $result): array
    {
        $response = is_array($result) ? $result : ['output' => $result];

        return [
            'functionResponse' => [
                'name' => $functionName,
                'response' => $response,
            ],
        ];
    }

    /**
     * Adapt standard JSON Schema into Gemini OpenAPI 3.0 compatible format.
     */
    private function adaptSchemaForGemini(array $schema): array
    {
        if (isset($schema['type'])) {
            if (is_array($schema['type'])) {
                // Remove null if present and take first scalar
                $nonNull = array_filter($schema['type'], static fn($t) => $t !== 'null');
                $schema['type'] = strtoupper((string)reset($nonNull));
                $schema['nullable'] = true;
            } else {
                $schema['type'] = strtoupper((string)$schema['type']);
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = $this->adaptSchemaForGemini($prop);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->adaptSchemaForGemini($schema['items']);
        }

        return $schema;
    }
}
