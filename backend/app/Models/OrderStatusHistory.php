<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $changed_by
 * @property string|null $note
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read User|null $actor
 */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['order_id', 'from_status', 'to_status', 'changed_by', 'note'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
