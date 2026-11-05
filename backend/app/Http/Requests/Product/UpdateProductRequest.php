<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
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
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'product_code' => ['sometimes', 'required', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('products', 'product_code')->ignore($product->id)],
            'product_name' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'low_stock_threshold' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            // Stock only changes through the ledger (stock adjustment endpoint).
            'stock' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'stock.prohibited' => 'Stok tidak dapat diubah langsung. Gunakan stock adjustment.',
        ];
    }
}
