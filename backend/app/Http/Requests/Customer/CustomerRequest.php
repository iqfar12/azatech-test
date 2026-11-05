<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\Gender;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for both create (POST) and update (PUT). On PUT every field is optional
 * ("sometimes") so partial updates are allowed, but a present field must be valid.
 */
final class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer
            ? ($this->user()?->can('update', $customer) ?? false)
            : ($this->user()?->can('create', Customer::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : null,
            'phone' => is_string($this->input('phone')) ? preg_replace('/[\s\-]/', '', $this->input('phone')) : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'customer_name' => [$required, 'string', 'min:2', 'max:150'],
            'customer_address' => [$required, 'string', 'min:2', 'max:255'],
            'gender' => [$required, Rule::enum(Gender::class)],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:191'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor telepon harus 8–15 digit angka (boleh diawali +).',
            'gender' => 'Gender harus Pria atau Wanita.',
        ];
    }
}
