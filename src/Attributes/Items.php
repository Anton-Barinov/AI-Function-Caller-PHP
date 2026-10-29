<?php

declare(strict_types=1);

namespace AiFunctionCaller\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Items
{
    /**
     * @param string $type The PHP type name (e.g. SubDto::class, 'string', 'int', 'float')
     * @param string|null $description Description for array items
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $description = null
    ) {
    }
}
