<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Order::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['bail', 'required', 'uuid', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:'.config('shop.orders.max_items')],
            'items.*' => ['required', 'array:product_id,quantity'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('shop.orders.max_quantity_per_item')],
            // Prices and totals are computed by the server. Sending them is a client bug → reject loudly.
            'total_amount' => ['prohibited'],
            'total' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_id.exists' => 'Pelanggan tidak ditemukan.',
            'items.required' => 'Order minimal berisi 1 produk.',
            'items.*.array' => 'Item hanya boleh berisi product_id dan quantity (harga dihitung oleh server).',
            'items.*.product_id.distinct' => 'Produk yang sama tidak boleh muncul dua kali.',
            'items.*.quantity.min' => 'Jumlah minimal 1.',
            'total_amount.prohibited' => 'Total dihitung oleh server.',
            'total.prohibited' => 'Total dihitung oleh server.',
        ];
    }

    /**
     * @return list<array{product_id: int, quantity: int}>
     */
    public function items(): array
    {
        /** @var list<array{product_id: int|string, quantity: int|string}> $items */
        $items = array_values($this->validated('items'));

        return array_map(static fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
        ], $items);
    }

    /**
     * Canonical hash of the *semantic* payload, used to detect Idempotency-Key reuse
     * with a different body (JSON whitespace / key order do not matter).
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'customer_id' => mb_strtolower((string) $this->validated('customer_id')),
            'items' => $this->items(),
        ], JSON_THROW_ON_ERROR));
    }
}
