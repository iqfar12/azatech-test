<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Order\OrderStatus;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard summary, cached in Redis.
 *
 * Invalidation strategy (cache-aside + explicit invalidation):
 *  - every write that changes a number shown here (order created, status changed,
 *    stock adjusted, product created/updated/deleted) calls invalidate() AFTER COMMIT,
 *    so readers never re-cache uncommitted data;
 *  - a short TTL is the safety net for the small race where a reader recomputes
 *    from a snapshot taken just before a concurrent commit.
 */
final class DashboardService
{
    public const CACHE_KEY = 'dashboard:summary:v1';

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        /** @var array<string, mixed> $summary */
        $summary = Cache::remember(
            self::CACHE_KEY,
            (int) config('shop.dashboard.cache_ttl_seconds'),
            fn (): array => $this->compute(),
        );

        return $summary;
    }

    public function invalidate(): void
    {
        // If we are inside a transaction, wait for the commit; otherwise run immediately.
        DB::afterCommit(static fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $tz = (string) config('shop.business_timezone');
        $todayStart = CarbonImmutable::now($tz)->startOfDay()->utc();
        $monthStart = CarbonImmutable::now($tz)->startOfMonth()->utc();
        $revenue = OrderStatus::revenueValues();
        $in = implode(',', array_fill(0, count($revenue), '?'));

        // One pass over the orders of this month thanks to FILTER aggregates.
        $orders = DB::selectOne(
            <<<SQL
                SELECT
                    COUNT(*) FILTER (WHERE order_date >= ?)                                   AS orders_today,
                    COALESCE(SUM(total_amount) FILTER (WHERE order_date >= ? AND status IN ($in)), 0) AS revenue_today,
                    COUNT(*)                                                                  AS orders_this_month,
                    COALESCE(SUM(total_amount) FILTER (WHERE status IN ($in)), 0)             AS revenue_this_month
                FROM orders
                WHERE order_date >= ?
            SQL,
            [$todayStart, $todayStart, ...$revenue, ...$revenue, $monthStart],
        );

        $statusCounts = DB::table('orders')
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Paid->value])
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $products = DB::selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS total_products,
                       COUNT(*) FILTER (WHERE stock < low_stock_threshold) AS low_stock_products
                FROM products
                WHERE deleted_at IS NULL
            SQL,
        );

        return [
            'orders_today' => (int) $orders->orders_today,
            'revenue_today' => Money::fromDecimal((string) $orders->revenue_today)->toDecimal(),
            'orders_this_month' => (int) $orders->orders_this_month,
            'revenue_this_month' => Money::fromDecimal((string) $orders->revenue_this_month)->toDecimal(),
            'pending_orders' => (int) ($statusCounts[OrderStatus::Pending->value] ?? 0),
            'awaiting_shipment' => (int) ($statusCounts[OrderStatus::Paid->value] ?? 0),
            'total_products' => (int) $products->total_products,
            'low_stock_products' => (int) $products->low_stock_products,
            'total_customers' => DB::table('customers')->whereNull('deleted_at')->count(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
