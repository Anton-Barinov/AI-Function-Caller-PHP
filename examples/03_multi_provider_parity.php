<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Attributes\Field;
use AiFunctionCaller\Attributes\Tool;
use AiFunctionCaller\FunctionCaller;

class SupportTicketService
{
    #[Tool('create_support_ticket', 'File an IT support or customer assistance ticket')]
    public function createTicket(
        #[Field('Summary of the problem')]
        string $title,

        #[Field('Priority level: low, medium, high, urgent')]
        string $priority,

        #[Field('Optional department assignment')]
        ?string $department = null
    ): array {
        return [
            'ticket_id' => 'TCK-' . mt_rand(10000, 99999),
            'title' => $title,
            'priority' => $priority,
            'department' => $department ?? 'General Support',
            'status' => 'OPEN',
            'created_at' => date(DATE_ATOM),
        ];
    }
}

$caller = FunctionCaller::create();
$caller->registerClass(new SupportTicketService());

echo "======================================================\n";
echo "1. OpenAI Tool Definition\n";
echo "======================================================\n";
echo json_encode($caller->getOpenAiTools(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "======================================================\n";
echo "2. Anthropic Claude Tool Definition\n";
echo "======================================================\n";
echo json_encode($caller->getAnthropicTools(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "======================================================\n";
echo "3. Google Gemini Tool Declaration\n";
echo "======================================================\n";
echo json_encode($caller->getGeminiTools(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "======================================================\n";
echo "4. Simulating Incoming Anthropic Tool Call\n";
echo "======================================================\n";
$anthropicCall = [
    'type' => 'tool_use',
    'id' => 'toolu_01A098bc',
    'name' => 'create_support_ticket',
    'input' => [
        'title' => 'Database connection timeout on production',
        'priority' => 'urgent',
        'department' => 'DevOps',
    ],
];
$anthropicResult = $caller->handleAnthropicToolCall($anthropicCall);
echo json_encode($anthropicResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "======================================================\n";
echo "5. Simulating Incoming Gemini Function Call\n";
echo "======================================================\n";
$geminiCall = [
    'name' => 'create_support_ticket',
    'args' => [
        'title' => 'Billing inquiry regarding invoice #901',
        'priority' => 'low',
    ],
];
$geminiResult = $caller->handleGeminiToolCall($geminiCall);
echo json_encode($geminiResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
