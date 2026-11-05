<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * Order lifecycle. The transition table lives here, in the domain, so the rule
 * "what may follow what" has exactly one source of truth (used by the state
 * machine, the API resource "allowed_transitions" and the unit tests).
 *
 *   PENDING ──pay──▶ PAID ──ship──▶ SHIPPED ──complete──▶ COMPLETED
 *      │               │
 *      └────cancel─────┴──▶ CANCELLED
 */
enum OrderStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Shipped = 'SHIPPED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Cancelled],
            self::Paid => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Statuses that count as revenue in reports and dashboards.
     *
     * @return list<self>
     */
    public static function revenueStatuses(): array
    {
        return [self::Paid, self::Shipped, self::Completed];
    }

    /**
     * @return list<string>
     */
    public static function revenueValues(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::revenueStatuses());
    }

    /**
     * Name of the timestamp column filled when entering this status.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Paid => 'paid_at',
            self::Shipped => 'shipped_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
            self::Pending => null,
        };
    }
}
