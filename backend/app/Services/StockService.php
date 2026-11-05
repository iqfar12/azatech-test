<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Jobs\SendLowStockAlert;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The ONLY code path allowed to change products.stock.
 *
 * Concurrency contract:
 *  - callers lock rows through lockProducts(), inside a DB transaction;
 *  - rows are always locked in ascending product id order, so two transactions
 *    that touch overlapping product sets acquire locks in the same order and can
 *    never wait on each other in a cycle (no deadlock);
 *  - every change writes one append-only stock_movements row with stock_after.
 */
final class StockService
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * SELECT ... FROM products WHERE id IN (...) ORDER BY id FOR UPDATE
     *
     * @param  array<int, int>  $productIds
     * @param  bool  $withTrashed  restoring stock of a cancelled order must also reach soft-deleted products
     * @return Collection<int, Product> keyed by product id
     */
    public function lockProducts(array $productIds, bool $withTrashed = false): Collection
    {
        $this->assertInTransaction();

        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids);

        return Product::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Applies a signed change to a product that the current transaction has already locked.
     */
    public function apply(
        Product $lockedProduct,
        int $change,
        StockMovementType $type,
        ?User $actor,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $note = null,
    ): StockMovement {
        $this->assertInTransaction();

        if ($change === 0) {
            throw new LogicException('A stock movement must change the stock.');
        }

        $before = $lockedProduct->stock;
        $after = $before + $change;

        if ($after < 0) {
            throw new BusinessRuleException(
                "Stok {$lockedProduct->product_name} tidak boleh kurang dari 0 (tersisa {$before})",
                422,
                ['quantity_change' => ["Stok tersisa {$before}, perubahan {$change} membuat stok minus."]],
            );
        }

        // Plain UPDATE on the locked row; the CHECK (stock >= 0) constraint is the final safety net.
        $lockedProduct->stock = $after;
        $lockedProduct->save();

        $movement = StockMovement::query()->create([
            'product_id' => $lockedProduct->id,
            'type' => $type,
            'quantity_change' => $change,
            'stock_after' => $after,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $actor?->id,
        ]);

        // Notify only when the stock *crosses* the threshold, not on every sale below it.
        $threshold = $lockedProduct->low_stock_threshold;
        if ($before >= $threshold && $after < $threshold) {
            SendLowStockAlert::dispatch($lockedProduct->id, $movement->id)->afterCommit();
        }

        return $movement;
    }

    /**
     * Opening balance when a product is created.
     */
    public function recordInitial(Product $product, int $quantity, ?User $actor): ?StockMovement
    {
        if ($quantity <= 0) {
            return null;
        }

        return DB::transaction(function () use ($product, $quantity, $actor): StockMovement {
            $locked = $this->lockProducts([$product->id])->firstOrFail();

            return $this->apply($locked, $quantity, StockMovementType::Initial, $actor, note: 'Stok awal');
        });
    }

    /**
     * Manual stock adjustment (stock opname). Admin only — enforced by ProductPolicy.
     */
    public function adjust(Product $product, int $change, string $note, User $actor): StockMovement
    {
        $movement = DB::transaction(function () use ($product, $change, $note, $actor): StockMovement {
            $locked = $this->lockProducts([$product->id])->first();

            if ($locked === null) {
                throw new BusinessRuleException('Produk tidak ditemukan atau sudah dihapus', 404);
            }

            $movement = $this->apply($locked, $change, StockMovementType::Adjustment, $actor, 'adjustment', null, $note);

            $this->audit->record($actor, 'stock.adjusted', 'product', (string) $locked->id, [
                'quantity_change' => $change,
                'stock_after' => $movement->stock_after,
                'note' => $note,
            ]);

            return $movement;
        }, 3);

        $this->dashboard->invalidate();

        return $movement;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock changes must run inside a database transaction.');
        }
    }
}
