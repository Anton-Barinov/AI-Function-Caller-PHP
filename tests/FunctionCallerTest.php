<?php

declare(strict_types=1);

namespace AiFunctionCaller\Tests;

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Attributes\Field;
use AiFunctionCaller\Attributes\Tool;
use AiFunctionCaller\FunctionCaller;

class SampleService
{
    #[Tool('add_numbers', 'Adds two numbers')]
    public function add(
        #[Field('First number')]
        int $a,

        #[Field('Second number')]
        int $b
    ): int {
        return $a + $b;
    }
}

class FunctionCallerTest
{
    public function run(): void
    {
        $caller = FunctionCaller::create();

        // 1. Register closure tool
        $caller->registerTool(
            'greet',
            fn(string $name, string $greeting = 'Hello') => "$greeting, $name!",
            'Greet a user'
        );

        $res1 = $caller->call('greet', ['name' => 'Alice']);
        assert($res1 === 'Hello, Alice!', 'Closure invocation with default param');

        $res2 = $caller->call('greet', json_encode(['name' => 'Bob', 'greeting' => 'Hi']));
        assert($res2 === 'Hi, Bob!', 'Closure invocation with json string');

        // 2. Register class tools via attribute
        $service = new SampleService();
        $caller->registerClass($service);

        $res3 = $caller->call('add_numbers', ['a' => 15, 'b' => 27]);
        assert($res3 === 42, 'Method invocation should return 42');

        // 3. Test handleOpenAiToolCall
        $oaCall = [
            'id' => 'call_abc',
            'type' => 'function',
            'function' => [
                'name' => 'add_numbers',
                'arguments' => '{"a": 100, "b": 200}',
            ],
        ];
        $oaResp = $caller->handleOpenAiToolCall($oaCall);
        assert($oaResp['role'] === 'tool', 'OpenAI role');
        assert($oaResp['tool_call_id'] === 'call_abc', 'OpenAI call id');
        assert($oaResp['content'] === '300', 'OpenAI content result');

        // 4. Test handleAnthropicToolCall
        $anCall = [
            'id' => 'toolu_xyz',
            'name' => 'add_numbers',
            'input' => ['a' => 50, 'b' => 50],
        ];
        $anResp = $caller->handleAnthropicToolCall($anCall);
        assert($anResp['type'] === 'tool_result', 'Anthropic type');
        assert($anResp['tool_use_id'] === 'toolu_xyz', 'Anthropic tool_use_id');
        assert($anResp['content'] === '100', 'Anthropic content result');

        // 5. Test handleGeminiToolCall
        $gmCall = [
            'name' => 'add_numbers',
            'args' => ['a' => 7, 'b' => 8],
        ];
        $gmResp = $caller->handleGeminiToolCall($gmCall);
        assert($gmResp['functionResponse']['name'] === 'add_numbers', 'Gemini response name');
        assert($gmResp['functionResponse']['response']['output'] === 15, 'Gemini response output');
    }
}
