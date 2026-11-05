<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

final class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjustStock', $this->route('product')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Signed delta: +50 restock, -3 broken items found during stock opname.
            'quantity_change' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity_change.not_in' => 'Perubahan stok tidak boleh 0.',
            'note.required' => 'Catatan wajib diisi untuk stock adjustment.',
        ];
    }
}
