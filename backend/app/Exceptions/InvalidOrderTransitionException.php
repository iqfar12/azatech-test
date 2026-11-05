<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Order\OrderStatus;

final class InvalidOrderTransitionException extends BusinessRuleException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            "Order berstatus {$from->value} tidak dapat diubah menjadi {$to->value}",
            409,
            ['status' => ["Transisi {$from->value} → {$to->value} tidak diizinkan"]],
        );
    }
}
