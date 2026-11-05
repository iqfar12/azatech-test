<?php

declare(strict_types=1);

namespace App\Exceptions;

final class InsufficientStockException extends BusinessRuleException
{
    /**
     * @param  array<string, list<string>>  $errors  keyed like "items.1.quantity"
     */
    public static function withErrors(array $errors, string $message = 'Stok tidak mencukupi'): self
    {
        return new self($message, 422, $errors);
    }
}
