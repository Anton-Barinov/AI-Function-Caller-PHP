<?php

declare(strict_types=1);

namespace AiFunctionCaller\Tests;

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Providers\AnthropicProvider;
use AiFunctionCaller\Providers\GeminiProvider;
use AiFunctionCaller\Providers\OpenAiProvider;

class ProviderParityTest
{
    public function run(): void
    {
        $openai = new OpenAiProvider();
        $anthropic = new AnthropicProvider();
        $gemini = new GeminiProvider();

        $params = [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'default' => 5],
            ],
            'required' => ['query'],
        ];

        // 1. OpenAI
        $oaTool = $openai->formatTool('search', 'Search tool', $params, strict: true);
        assert($oaTool['type'] === 'function', 'OpenAI type must be function');
        assert($oaTool['function']['name'] === 'search', 'OpenAI function name');
        assert($oaTool['function']['strict'] === true, 'OpenAI strict');

        $oaResult = $openai->formatToolResult('call_123', ['status' => 'ok']);
        assert($oaResult['role'] === 'tool', 'OpenAI tool result role');
        assert($oaResult['tool_call_id'] === 'call_123', 'OpenAI tool_call_id');

        // 2. Anthropic
        $anTool = $anthropic->formatTool('search', 'Search tool', $params);
        assert($anTool['name'] === 'search', 'Anthropic tool name');
        assert($anTool['input_schema'] === $params, 'Anthropic input_schema');

        $anResult = $anthropic->formatToolResult('toolu_999', ['status' => 'ok'], false);
        assert($anResult['type'] === 'tool_result', 'Anthropic tool result type');
        assert($anResult['tool_use_id'] === 'toolu_999', 'Anthropic tool_use_id');
        assert($anResult['is_error'] === false, 'Anthropic is_error');

        // 3. Gemini
        $gmTool = $gemini->formatTool('search', 'Search tool', $params);
        assert($gmTool['name'] === 'search', 'Gemini tool name');
        assert($gmTool['parameters']['type'] === 'OBJECT', 'Gemini type capitalized');
        assert($gmTool['parameters']['properties']['query']['type'] === 'STRING', 'Gemini query capitalized');

        $gmResult = $gemini->formatToolResult('search', ['count' => 10]);
        assert(isset($gmResult['functionResponse']), 'Gemini functionResponse key');
        assert($gmResult['functionResponse']['name'] === 'search', 'Gemini functionResponse name');
        assert($gmResult['functionResponse']['response']['count'] === 10, 'Gemini response count');
    }
}
