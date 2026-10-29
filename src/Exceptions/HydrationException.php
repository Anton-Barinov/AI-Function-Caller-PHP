<?php

declare(strict_types=1);

namespace AiFunctionCaller\Exceptions;

class HydrationException extends AiFunctionCallerException
{
    public function __construct(
        string $message,
        private readonly ?string $targetClass = null,
        private readonly ?string $property = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if ($targetClass !== null) {
            $prefix = $property !== null
                ? sprintf('[%s::$%s] ', $targetClass, $property)
                : sprintf('[%s] ', $targetClass);
            $message = $prefix . $message;
        }

        parent::__construct($message, $code, $previous);
    }

    public function getTargetClass(): ?string
    {
        return $this->targetClass;
    }

    public function getProperty(): ?string
    {
        return $this->property;
    }
}
