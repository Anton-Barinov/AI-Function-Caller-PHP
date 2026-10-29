<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Attributes\Description;
use AiFunctionCaller\Attributes\Field;
use AiFunctionCaller\Attributes\Items;
use AiFunctionCaller\Attributes\Max;
use AiFunctionCaller\Attributes\Min;
use AiFunctionCaller\Attributes\Pattern;
use AiFunctionCaller\FunctionCaller;
use AiFunctionCaller\Exceptions\ValidationException;

// 1. Define Enum
enum OrderStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
}

// 2. Define Nested DTOs
#[Description('An individual item line in an order')]
class OrderItem
{
    public function __construct(
        #[Field('Stock Keeping Unit (format: SKU-XXXX)')]
        #[Pattern('/^SKU-[0-9]{4}$/')]
        public readonly string $sku,

        #[Field('Product title or name')]
        public readonly string $name,

        #[Field('Quantity of units ordered')]
        #[Min(1)]
        #[Max(100)]
        public readonly int $quantity,

        #[Field('Price per single unit in USD')]
        #[Min(0.01)]
        public readonly float $unitPrice
    ) {
    }
}

#[Description('Customer shipping and contact details')]
class CustomerDetails
{
    public function __construct(
        #[Field('Customer full legal name')]
        public readonly string $fullName,

        #[Field('Valid email address for notification')]
        #[Pattern('/^[^\s@]+@[^\s@]+\.[^\s@]+$/')]
        public readonly string $email,

        #[Field('Optional shipping address')]
        public readonly ?string $address = null
    ) {
    }
}

// 3. Define Main Order DTO
#[Description('Customer purchase order payload')]
class PurchaseOrder
{
    /**
     * @param list<OrderItem> $items
     */
    public function __construct(
        #[Field('Unique alphanumeric order identifier')]
        #[Pattern('/^ORD-[0-9]+$/')]
        public readonly string $orderId,

        #[Field('Customer details')]
        public readonly CustomerDetails $customer,

        #[Field('Current fulfillment status')]
        public readonly OrderStatus $status,

        #[Field('List of ordered items')]
        #[Items(OrderItem::class)]
        public readonly array $items,

        #[Field('Order placement timestamp in ISO 8601')]
        public readonly DateTimeImmutable $createdAt,

        #[Field('Optional internal notes or gift message')]
        public readonly ?string $notes = null
    ) {
    }
}

// 4. Initialize FunctionCaller
$caller = FunctionCaller::create();

echo "=== 1. Generate OpenAI Structured Outputs Schema ===\n";
$openAiSchema = $caller->getOpenAiStructuredOutput(PurchaseOrder::class);
echo json_encode($openAiSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== 2. Simulate & Parse LLM JSON Response ===\n";
$llmJsonResponse = <<<'JSON'
{
    "orderId": "ORD-94812",
    "customer": {
        "fullName": "Alice Johnson",
        "email": "alice@example.com",
        "address": "456 Tech Parkway, San Francisco, CA"
    },
    "status": "shipped",
    "items": [
        {
            "sku": "SKU-1024",
            "name": "Ergonomic Mechanical Keyboard",
            "quantity": 2,
            "unitPrice": 129.99
        },
        {
            "sku": "SKU-2048",
            "name": "USB-C Braided Cable 2m",
            "quantity": 3,
            "unitPrice": 14.50
        }
    ],
    "createdAt": "2026-10-29T14:30:00Z",
    "notes": "Handle with care, leave at front desk."
}
JSON;

/** @var PurchaseOrder $order */
$order = $caller->parse(PurchaseOrder::class, $llmJsonResponse);

echo "✓ Successfully parsed into PurchaseOrder object:\n";
echo "  Order ID: {$order->orderId}\n";
echo "  Customer: {$order->customer->fullName} ({$order->customer->email})\n";
echo "  Status: {$order->status->value} (Enum instance)\n";
echo "  Created: {$order->createdAt->format('Y-m-d H:i:s')}\n";
echo "  Items count: " . count($order->items) . "\n";
foreach ($order->items as $i => $item) {
    echo "    [#{$i}] SKU: {$item->sku} | {$item->name} x{$item->quantity} @ \${$item->unitPrice}\n";
}

echo "\n=== 3. Simulate Invalid LLM Output (Triggering Validation) ===\n";
$badJson = <<<'JSON'
{
    "orderId": "INVALID_ID_FORMAT",
    "customer": {
        "fullName": "Bob",
        "email": "not-an-email"
    },
    "status": "unknown_status",
    "items": [
        {
            "sku": "BAD-SKU",
            "name": "Widget",
            "quantity": 0,
            "unitPrice": -5.00
        }
    ],
    "createdAt": "not-a-date"
}
JSON;

try {
    $caller->parse(PurchaseOrder::class, $badJson);
} catch (ValidationException $e) {
    echo "✓ Successfully caught expected validation errors:\n";
    foreach ($e->getErrors() as $field => $errs) {
        echo "  • {$field}: " . implode(', ', $errs) . "\n";
    }
}
