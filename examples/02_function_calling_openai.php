<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Attributes\Description;
use AiFunctionCaller\Attributes\Field;
use AiFunctionCaller\Attributes\Min;
use AiFunctionCaller\Attributes\Tool;
use AiFunctionCaller\FunctionCaller;

class WeatherService
{
    #[Tool('get_current_weather', 'Get current weather and temperature for a given city')]
    public function getWeather(
        #[Field('The city name, e.g. London, Tokyo, New York')]
        string $city,

        #[Field('Temperature scale (celsius or fahrenheit)')]
        string $unit = 'celsius'
    ): array {
        return [
            'city' => $city,
            'temperature' => 21.5,
            'unit' => $unit,
            'condition' => 'Sunny with scattered clouds',
            'humidity' => 58,
        ];
    }
}

class CalculatorService
{
    #[Tool('calculate_tax', 'Calculate VAT/sales tax for an amount')]
    public function calculateTax(
        #[Field('Total amount in standard currency')]
        #[Min(0)]
        float $amount,

        #[Field('Tax rate percentage (e.g. 20 for 20%)')]
        #[Min(0)]
        float $ratePercentage = 20.0
    ): array {
        $tax = $amount * ($ratePercentage / 100);
        return [
            'amount' => $amount,
            'rate' => $ratePercentage,
            'tax' => round($tax, 2),
            'total' => round($amount + $tax, 2),
        ];
    }
}

$caller = FunctionCaller::create();
$caller->registerClass(new WeatherService());
$caller->registerClass(new CalculatorService());

echo "=== Registered Tools (OpenAI Format) ===\n";
$tools = $caller->getOpenAiTools();
echo json_encode($tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== Simulating OpenAI Tool Call Execution ===\n";
$incomingToolCall = [
    'id' => 'call_999888',
    'type' => 'function',
    'function' => [
        'name' => 'get_current_weather',
        'arguments' => json_encode(['city' => 'Paris', 'unit' => 'celsius']),
    ],
];

echo "Incoming tool call from LLM:\n" . json_encode($incomingToolCall, JSON_PRETTY_PRINT) . "\n\n";

$resultMessage = $caller->handleOpenAiToolCall($incomingToolCall);
echo "Tool response ready to append to OpenAI conversation history:\n";
echo json_encode($resultMessage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== Simulating Calculator Tool Call Execution ===\n";
$calcCall = [
    'id' => 'call_111222',
    'type' => 'function',
    'function' => [
        'name' => 'calculate_tax',
        'arguments' => '{"amount": 150.0, "ratePercentage": 19.0}',
    ],
];

$calcResponse = $caller->handleOpenAiToolCall($calcCall);
echo json_encode($calcResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
