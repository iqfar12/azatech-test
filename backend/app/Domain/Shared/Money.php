<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Immutable money amount stored as integer minor units (sen).
 *
 * Floats are never used for money: 0.1 + 0.2 !== 0.3. PostgreSQL returns
 * decimal columns as strings ("12000.00"), which we parse exactly.
 */
final readonly class Money
{
    private function __construct(public int $minor)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function ofMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * Parses "12000", "12000.5" or "12000.50". More than 2 decimals is rejected
     * instead of silently rounded.
     */
    public static function fromDecimal(string|int $amount): self
    {
        $amount = trim((string) $amount);

        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $amount, $m) !== 1) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $minor = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$minor : $minor);
    }

    public function add(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minor * $factor);
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function toDecimal(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
