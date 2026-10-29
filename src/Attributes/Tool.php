<?php

declare(strict_types=1);

namespace AiFunctionCaller\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS)]
class Tool
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null
    ) {
    }
}
