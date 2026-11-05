<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * INV-YYYYMMDD-NNNN, numbered per business day.
 *
 * Atomic upsert on a per-day counter row. Called inside the order transaction,
 * so a rolled-back order also rolls back its number (no gaps, no duplicates).
 * It is called as the LAST lock of the order transaction to keep the time the
 * counter row stays locked as short as possible.
 */
final class OrderNumberGenerator
{
    public function next(CarbonImmutable $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Order numbers must be generated inside the order transaction.');
        }

        $local = $at->setTimezone((string) config('shop.business_timezone'));

        $row = DB::selectOne(
            <<<'SQL'
                INSERT INTO order_number_sequences (sequence_date, last_number)
                VALUES (?, 1)
                ON CONFLICT (sequence_date)
                DO UPDATE SET last_number = order_number_sequences.last_number + 1
                RETURNING last_number
            SQL,
            [$local->toDateString()],
        );

        return sprintf(
            '%s-%s-%04d',
            config('shop.orders.number_prefix'),
            $local->format('Ymd'),
            (int) $row->last_number,
        );
    }
}
