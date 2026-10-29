<?php

declare(strict_types=1);

namespace AiFunctionCaller\Exceptions;

class SchemaException extends AiFunctionCallerException
{
    public function __construct(
        string $message,
        private readonly ?string $targetClass = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if ($targetClass !== null) {
            $message = sprintf('[%s] %s', $targetClass, $message);
        }

        parent::__construct($message, $code, $previous);
    }

    public function getTargetClass(): ?string
    {
        return $this->targetClass;
    }
}
