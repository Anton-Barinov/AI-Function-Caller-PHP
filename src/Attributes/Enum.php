<?php

declare(strict_types=1);

namespace AiFunctionCaller\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Enum
{
    /**
     * @param list<string|int|float> $values
     */
    public function __construct(
        public readonly array $values
    ) {
    }
}
