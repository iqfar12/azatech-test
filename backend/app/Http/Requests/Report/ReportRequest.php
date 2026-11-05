<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ReportRequest extends FormRequest
{
    private const MAX_RANGE_DAYS = 731;

    public function authorize(): bool
    {
        return $this->user()?->can('view-reports') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['sometimes', 'in:day,month'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sort_by' => ['sometimes', 'in:quantity,revenue'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $days = CarbonImmutable::parse((string) $this->input('from'))
                    ->diffInDays(CarbonImmutable::parse((string) $this->input('to')));
                if ($days > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', 'Rentang tanggal maksimal 2 tahun.');
                }
            },
        ];
    }
}
