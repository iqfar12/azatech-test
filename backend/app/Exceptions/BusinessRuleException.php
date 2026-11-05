<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An expected, client-caused rejection of a request (not a bug).
 * Rendered by ApiExceptionRenderer with its own HTTP status and per-field errors.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly array $errors = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
