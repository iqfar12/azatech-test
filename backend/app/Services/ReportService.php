<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Order\OrderStatus;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * All aggregation happens in PostgreSQL (one query per report, no N+1).
 *
 * Index usage:
 *  - sales:        orders_report_idx (order_date, status) INCLUDE (total_amount) → index-only range scan
 *  - top-products: orders_report_idx to find the orders, order_items_product_id_index /
 *                  order_items (order_id, product_id) unique index for the join.
 */
final class ReportService
{
    /**
     * @return array{group_by: string, from: string, to: string, summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function sales(string $from, string $to, string $groupBy): array
    {
        if (! in_array($groupBy, ['day', 'month'], true)) {
            throw new InvalidArgumentException('group_by must be day or month');
        }

        [$fromUtc, $toUtc, $tz] = $this->range($from, $to);
        $statuses = OrderStatus::revenueValues();
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $interval = $groupBy === 'day' ? '1 day' : '1 month';
        $labelFormat = $groupBy === 'day' ? 'YYYY-MM-DD' : 'YYYY-MM';

        // $groupBy / $interval / $labelFormat come from the whitelist above, never from raw input.
        // generate_series fills empty periods with zeros so the chart has no holes.
        $rows = DB::select(
            <<<SQL
                WITH buckets AS (
                    SELECT generate_series(
                        date_trunc('{$groupBy}', CAST(? AS timestamptz) AT TIME ZONE CAST(? AS text)),
                        date_trunc('{$groupBy}', CAST(? AS timestamptz) AT TIME ZONE CAST(? AS text)),
                        interval '{$interval}'
                    ) AS period
                ),
                agg AS (
                    SELECT date_trunc('{$groupBy}', o.order_date AT TIME ZONE CAST(? AS text)) AS period,
                           COUNT(*)            AS total_orders,
                           SUM(o.total_amount) AS revenue
                    FROM orders o
                    WHERE o.status IN ({$in})
                      AND o.order_date >= CAST(? AS timestamptz)
                      AND o.order_date <  CAST(? AS timestamptz)
                    GROUP BY 1
                )
                SELECT to_char(b.period, '{$labelFormat}') AS period,
                       COALESCE(a.total_orders, 0)       AS total_orders,
                       COALESCE(a.revenue, 0)            AS revenue
                FROM buckets b
                LEFT JOIN agg a ON a.period = b.period
                ORDER BY b.period
            SQL,
            [
                $fromUtc, $tz, $toUtc->subSecond(), $tz,
                $tz, ...$statuses, $fromUtc, $toUtc,
            ],
        );

        $totalOrders = 0;
        $totalRevenue = Money::zero();
        $result = [];

        foreach ($rows as $row) {
            $revenue = Money::fromDecimal((string) $row->revenue);
            $totalOrders += (int) $row->total_orders;
            $totalRevenue = $totalRevenue->add($revenue);
            $result[] = [
                'period' => (string) $row->period,
                'total_orders' => (int) $row->total_orders,
                'revenue' => $revenue->toDecimal(),
            ];
        }

        return [
            'group_by' => $groupBy,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'total_orders' => $totalOrders,
                'revenue' => $totalRevenue->toDecimal(),
            ],
            'rows' => $result,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topProducts(string $from, string $to, int $limit, string $sortBy = 'quantity'): array
    {
        [$fromUtc, $toUtc] = $this->range($from, $to);
        $statuses = OrderStatus::revenueValues();
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $order = $sortBy === 'revenue'
            ? 'revenue DESC, quantity_sold DESC'
            : 'quantity_sold DESC, revenue DESC';

        $rows = DB::select(
            <<<SQL
                SELECT oi.product_id,
                       p.product_code,
                       p.product_name,
                       SUM(oi.quantity)            AS quantity_sold,
                       SUM(oi.subtotal)            AS revenue,
                       COUNT(DISTINCT oi.order_id) AS order_count
                FROM order_items oi
                JOIN orders o   ON o.id = oi.order_id
                JOIN products p ON p.id = oi.product_id
                WHERE o.status IN ({$in})
                  AND o.order_date >= CAST(? AS timestamptz)
                  AND o.order_date <  CAST(? AS timestamptz)
                GROUP BY oi.product_id, p.product_code, p.product_name
                ORDER BY {$order}, oi.product_id
                LIMIT ?
            SQL,
            [...$statuses, $fromUtc, $toUtc, $limit],
        );

        return array_map(static fn (object $row): array => [
            'product_id' => (int) $row->product_id,
            'product_code' => (string) $row->product_code,
            'product_name' => (string) $row->product_name,
            'quantity_sold' => (int) $row->quantity_sold,
            'revenue' => Money::fromDecimal((string) $row->revenue)->toDecimal(),
            'order_count' => (int) $row->order_count,
        ], $rows);
    }

    /**
     * Interprets from/to as inclusive calendar dates in the business time zone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function range(string $from, string $to): array
    {
        $tz = (string) config('shop.business_timezone');
        $fromUtc = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $toUtc = CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()->utc();

        return [$fromUtc, $toUtc, $tz];
    }
}
