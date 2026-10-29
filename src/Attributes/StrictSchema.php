<?php

declare(strict_types=1);

namespace AiFunctionCaller\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class StrictSchema
{
    public function __construct(
        public readonly bool $strict = true
    ) {
    }
}
