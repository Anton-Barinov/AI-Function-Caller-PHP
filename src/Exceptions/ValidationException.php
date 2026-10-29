<?php

declare(strict_types=1);

namespace AiFunctionCaller\Exceptions;

class ValidationException extends AiFunctionCallerException
{
    /**
     * @param array<string, list<string>> $errors Map of property path => list of error messages
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if (!empty($errors)) {
            $formatted = [];
            foreach ($errors as $path => $fieldErrors) {
                $formatted[] = sprintf('%s: %s', $path, implode(', ', $fieldErrors));
            }
            $message .= ' [' . implode('; ', $formatted) . ']';
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
