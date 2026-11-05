<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Order\OrderStatus;
use App\Domain\Order\OrderTotalCalculator;
use App\Domain\Shared\Money;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly OrderTotalCalculator $calculator,
        private readonly OrderNumberGenerator $numbers,
        private readonly DashboardService $dashboard,
    ) {
    }

    /**
     * Creates a PENDING order and reserves stock, all-or-nothing.
     *
     * Transaction outline (READ COMMITTED):
     *   1. SELECT products ... ORDER BY id FOR UPDATE   (stock rows locked in a fixed order)
     *   2. validate stock for every line, collect ALL errors
     *   3. compute prices/totals on the server from the locked rows
     *   4. take the next order number (per-day counter row, locked only after validation passed)
     *   5. INSERT order + items, UPDATE stock, INSERT ledger rows + status history
     *
     * Concurrent orders for the same product serialise on step 1, so the second
     * transaction always sees the stock already decremented by the first one.
     *
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    public function create(User $actor, string $customerId, array $items): Order
    {
        $order = DB::transaction(function () use ($actor, $customerId, $items): Order {
            $products = $this->stock->lockProducts(array_column($items, 'product_id'));

            $errors = [];
            $hasStockError = false;
            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id']);

                if ($product === null) {
                    $errors["items.{$index}.product_id"] = ['Produk tidak ditemukan atau sudah dihapus'];

                    continue;
                }

                if ($product->stock < $item['quantity']) {
                    $hasStockError = true;
                    $errors["items.{$index}.quantity"] = [
                        "Stok {$product->product_name} tersisa {$product->stock}, diminta {$item['quantity']}",
                    ];
                }
            }

            if ($errors !== []) {
                throw InsufficientStockException::withErrors(
                    $errors,
                    $hasStockError ? 'Stok tidak mencukupi' : 'Produk tidak valid',
                );
            }

            // Prices come from the locked product rows — never from the client.
            $calculation = $this->calculator->calculate(array_map(
                static fn (array $item): array => [
                    'unit_price' => Money::fromDecimal($products[$item['product_id']]->price),
                    'quantity' => $item['quantity'],
                ],
                $items,
            ));

            $now = CarbonImmutable::now();

            $order = Order::query()->create([
                'order_number' => $this->numbers->next($now),
                'customer_id' => $customerId,
                'created_by' => $actor->id,
                'status' => OrderStatus::Pending,
                'total_amount' => $calculation['total']->toDecimal(),
                'order_date' => $now,
            ]);

            foreach ($items as $index => $item) {
                $product = $products[$item['product_id']];
                $line = $calculation['lines'][$index];

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'product_name' => $product->product_name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $line['unit_price']->toDecimal(),
                    'subtotal' => $line['subtotal']->toDecimal(),
                ]);

                $this->stock->apply(
                    $product,
                    -$item['quantity'],
                    StockMovementType::OrderOut,
                    $actor,
                    'order',
                    (string) $order->id,
                );
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Pending->value,
                'changed_by' => $actor->id,
                'note' => 'Order dibuat',
            ]);

            return $order;
        }, 3);

        $this->dashboard->invalidate();

        return $order->load(['customer', 'creator', 'items', 'histories.actor']);
    }
}
