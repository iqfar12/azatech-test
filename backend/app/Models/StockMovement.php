<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row. Never updated, never deleted.
 *
 * @property int $id
 * @property int $product_id
 * @property StockMovementType $type
 * @property int $quantity_change
 * @property int $stock_after
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $note
 * @property int|null $created_by
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read User|null $creator
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['product_id', 'type', 'quantity_change', 'stock_after', 'reference_type', 'reference_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity_change' => 'integer',
            'stock_after' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
