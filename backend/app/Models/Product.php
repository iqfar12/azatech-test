<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $product_code
 * @property string $product_name
 * @property int $stock
 * @property string $price decimal(12,2) as string
 * @property int $low_stock_threshold
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * `stock` is deliberately NOT fillable: it may only change through
     * App\Services\StockService so that every change lands in the ledger.
     *
     * @var list<string>
     */
    protected $fillable = ['product_code', 'product_name', 'price', 'low_stock_threshold'];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'price' => 'decimal:2',
            'low_stock_threshold' => 'integer',
        ];
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isLowStock(): bool
    {
        return $this->stock < $this->low_stock_threshold;
    }
}
