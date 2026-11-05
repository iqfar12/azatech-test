<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $stock_movement_id
 * @property int $stock_at_alert
 * @property int $threshold
 * @property \Carbon\CarbonImmutable|null $emailed_at
 * @property \Carbon\CarbonImmutable|null $read_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read Product $product
 */
class LowStockAlert extends Model
{
    /** @var list<string> */
    protected $fillable = ['product_id', 'stock_movement_id', 'stock_at_alert', 'threshold'];

    protected function casts(): array
    {
        return [
            'stock_at_alert' => 'integer',
            'threshold' => 'integer',
            'emailed_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
