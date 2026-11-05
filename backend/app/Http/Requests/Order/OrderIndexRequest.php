<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Domain\Order\OrderStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

final class OrderIndexRequest extends ListRequest
{
    protected function sortable(): array
    {
        return ['order_date', 'order_number', 'total_amount', 'status'];
    }

    protected function defaultSort(): string
    {
        return '-order_date';
    }

    protected function extraRules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(OrderStatus::class)],
            'customer_id' => ['sometimes', 'nullable', 'uuid'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Only compare when both are given; "after_or_equal:date_from" alone would fail for ?date_to=… only.
            'date_to' => array_merge(
                ['sometimes', 'nullable', 'date_format:Y-m-d'],
                $this->filled('date_from') ? ['after_or_equal:date_from'] : [],
            ),
        ];
    }
}
