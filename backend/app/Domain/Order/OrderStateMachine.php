<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Jobs\ProcessPaidOrder;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DashboardService;
use App\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order changes status.
 *
 * Every transition:
 *   1. re-reads the order with SELECT ... FOR UPDATE (so two concurrent requests
 *      on the same order are serialised and the second one sees the new status);
 *   2. validates the move against OrderStatus::allowedTransitions() → 409 if illegal;
 *   3. applies side effects in the same transaction (timestamps, stock restore + ledger,
 *      status history, audit log);
 *   4. schedules async work (invoice job) and cache invalidation AFTER COMMIT.
 *
 * Because of (1)+(2), "cancel twice" is safe: the second call finds CANCELLED and is
 * rejected with 409, so stock is restored exactly once. A partial unique index on
 * stock_movements (CANCEL_RESTORE per order+product) backs this up at the DB level.
 *
 * Role checks (who may trigger which transition) are enforced by OrderPolicy before
 * we get here; this class guards the domain invariants.
 */
final class OrderStateMachine
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DashboardService $dashboard,
        private readonly AuditLogger $audit,
    ) {
    }

    public function pay(Order $order, User $actor): Order
    {
        return $this->transition($order, OrderStatus::Paid, $actor);
    }

    public function ship(Order $order, User $actor): Order
    {
        return $this->transition($order, OrderStatus::Shipped, $actor);
    }

    public function complete(Order $order, User $actor): Order
    {
        return $this->transition($order, OrderStatus::Completed, $actor);
    }

    public function cancel(Order $order, User $actor, string $reason): Order
    {
        if (trim($reason) === '') {
            throw new BusinessRuleException(
                'Alasan pembatalan wajib diisi',
                422,
                ['cancel_reason' => ['Alasan pembatalan wajib diisi.']],
            );
        }

        return $this->transition($order, OrderStatus::Cancelled, $actor, trim($reason));
    }

    private function transition(Order $order, OrderStatus $target, User $actor, ?string $reason = null): Order
    {
        $updated = DB::transaction(function () use ($order, $target, $actor, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($target)) {
                throw InvalidOrderTransitionException::between($from, $target);
            }

            $now = CarbonImmutable::now();
            $locked->status = $target;

            $column = $target->timestampColumn();
            if ($column !== null) {
                $locked->setAttribute($column, $now);
            }

            if ($target === OrderStatus::Cancelled) {
                $locked->cancel_reason = $reason;
                $this->restoreStock($locked, $actor);
            }

            $locked->save();

            OrderStatusHistory::query()->create([
                'order_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'changed_by' => $actor->id,
                'note' => $reason,
            ]);

            if ($target === OrderStatus::Cancelled) {
                $this->audit->record($actor, 'order.cancelled', 'order', (string) $locked->id, [
                    'from_status' => $from->value,
                    'reason' => $reason,
                ]);
            }

            if ($target === OrderStatus::Paid) {
                // afterCommit: the worker must never pick up a job for an order whose PAID status was rolled back.
                ProcessPaidOrder::dispatch($locked->id)->afterCommit();
            }

            return $locked;
        }, 3);

        $this->dashboard->invalidate();

        return $updated->load(['customer', 'creator', 'items', 'histories.actor']);
    }

    /**
     * Gives the reserved stock back. Product rows are locked in ascending id order
     * (same order as order creation) to stay deadlock-free.
     */
    private function restoreStock(Order $order, User $actor): void
    {
        $items = $order->items()->get();
        $products = $this->stock->lockProducts($items->pluck('product_id')->all(), withTrashed: true);

        foreach ($items->sortBy('product_id') as $item) {
            $product = $products->get($item->product_id);
            if ($product === null) {
                // Products are restrict-on-delete and soft-deleted rows are included, so this is a bug.
                throw new \LogicException("Product {$item->product_id} of order {$order->id} not found.");
            }

            $this->stock->apply(
                $product,
                $item->quantity,
                StockMovementType::CancelRestore,
                $actor,
                'order',
                (string) $order->id,
                'Pembatalan order '.$order->order_number,
            );
        }
    }
}
