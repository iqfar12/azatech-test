<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProductService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DashboardService $dashboard,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  array{product_code: string, product_name: string, price: string|int|float, low_stock_threshold?: int, stock?: int}  $data
     */
    public function create(array $data, User $actor): Product
    {
        $product = DB::transaction(function () use ($data, $actor): Product {
            $initialStock = (int) ($data['stock'] ?? 0);
            unset($data['stock']);

            $product = Product::query()->create($data);
            // Opening balance goes through the ledger like any other stock change.
            $this->stock->recordInitial($product, $initialStock, $actor);

            return $product->refresh();
        });

        $this->dashboard->invalidate();

        return $product;
    }

    /**
     * Stock is not updatable here on purpose (use a stock adjustment).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $product->fill($data)->save();
        $this->dashboard->invalidate();

        return $product;
    }

    public function delete(Product $product, User $actor): void
    {
        DB::transaction(function () use ($product, $actor): void {
            $product->delete();
            $this->audit->record($actor, 'product.deleted', 'product', (string) $product->id, [
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'stock' => $product->stock,
            ]);
        });

        $this->dashboard->invalidate();
    }
}
