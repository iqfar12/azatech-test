<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * Pure function: given (unit price, quantity) lines, compute line subtotals and the order total.
 * No framework, no database — trivially unit-testable.
 */
final class OrderTotalCalculator
{
    /**
     * @param  array<array-key, array{unit_price: Money, quantity: int}>  $lines
     * @return array{lines: array<array-key, array{unit_price: Money, quantity: int, subtotal: Money}>, total: Money}
     */
    public function calculate(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('An order needs at least one line.');
        }

        $total = Money::zero();
        $result = [];

        foreach ($lines as $key => $line) {
            if ($line['quantity'] <= 0) {
                throw new InvalidArgumentException('Quantity must be greater than zero.');
            }
            if (! $line['unit_price']->isPositive()) {
                throw new InvalidArgumentException('Unit price must be greater than zero.');
            }

            $subtotal = $line['unit_price']->multiply($line['quantity']);
            $total = $total->add($subtotal);

            $result[$key] = $line + ['subtotal' => $subtotal];
        }

        return ['lines' => $result, 'total' => $total];
    }
}
