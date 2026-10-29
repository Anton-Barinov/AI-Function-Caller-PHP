<?php

declare(strict_types=1);

namespace AiFunctionCaller\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Pattern
{
    public function __construct(
        public readonly string $pattern
    ) {
    }
}
