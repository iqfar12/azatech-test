<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Order\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $order_number
 * @property string $customer_id
 * @property int $created_by
 * @property OrderStatus $status
 * @property string $total_amount
 * @property CarbonImmutable $order_date
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $invoice_sent_at
 * @property CarbonImmutable $created_at
 * @property-read Customer $customer
 * @property-read User $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderStatusHistory> $histories
 */
class Order extends Model
{
    /**
     * Only the order service writes orders; status & status timestamps are
     * changed exclusively by App\Domain\Order\OrderStateMachine.
     *
     * @var list<string>
     */
    protected $fillable = ['order_number', 'customer_id', 'created_by', 'status', 'total_amount', 'order_date'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'decimal:2',
            'order_date' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'invoice_sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        // Orders must keep rendering after a customer is soft-deleted.
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}
