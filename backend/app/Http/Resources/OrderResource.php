<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Order\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Order */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'total_amount' => $this->total_amount,
            'order_date' => $this->order_date->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'customer_name' => $this->customer->customer_name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ]),
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])->all()),
            'status_history' => $this->whenLoaded('histories', fn () => $this->histories->map(fn (OrderStatusHistory $h): array => [
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'note' => $h->note,
                'changed_by' => $h->relationLoaded('actor') && $h->actor !== null
                    ? ['id' => $h->actor->id, 'name' => $h->actor->name]
                    : null,
                'created_at' => $h->created_at->toIso8601String(),
            ])->all()),
            // UI hint only — the backend re-checks role (Policy) and status (state machine) on every action.
            'allowed_actions' => $this->allowedActions($request),
        ];
    }

    /** @return list<string> */
    private function allowedActions(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $actions = [
            'pay' => OrderStatus::Paid,
            'ship' => OrderStatus::Shipped,
            'complete' => OrderStatus::Completed,
            'cancel' => OrderStatus::Cancelled,
        ];

        $allowed = [];
        foreach ($actions as $ability => $target) {
            if ($this->status->canTransitionTo($target) && Gate::forUser($user)->allows($ability, $this->resource)) {
                $allowed[] = $ability;
            }
        }

        if (in_array($this->status, OrderStatus::revenueStatuses(), true)) {
            $allowed[] = 'download_invoice';
        }

        return $allowed;
    }
}
