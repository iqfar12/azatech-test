<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequest extends FormRequest
{
    /** Authorization runs before validation → a cashier gets 403, not 422. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('product_code'))) {
            $this->merge(['product_code' => strtoupper(trim($this->input('product_code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unique including soft-deleted rows (matches the DB unique index).
            'product_code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('products', 'product_code')],
            'product_name' => ['required', 'string', 'min:2', 'max:150'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_code.regex' => 'Kode produk hanya boleh huruf kapital, angka, dan tanda minus.',
            'product_code.unique' => 'Kode produk sudah digunakan.',
        ];
    }
}
