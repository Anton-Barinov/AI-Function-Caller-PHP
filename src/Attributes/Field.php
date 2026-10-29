<?php

declare(strict_types=1);

namespace AiFunctionCaller\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Field
{
    /**
     * @param string|null $description Human-readable explanation of the field
     * @param string|null $title Display title for the schema
     * @param mixed $default Default value if omitted
     * @param bool|null $required Explicit requirement flag
     * @param string|null $format JSON schema format (e.g. date-time, email, uri, uuid)
     * @param array<string, mixed> $extra Any additional JSON schema keys
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $title = null,
        public readonly mixed $default = null,
        public readonly ?bool $required = null,
        public readonly ?string $format = null,
        public readonly array $extra = []
    ) {
    }
}
