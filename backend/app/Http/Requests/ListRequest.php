<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-string contract for list endpoints:
 *   ?page=1&per_page=10&search=hydro&sort=-created_at
 * "sort" is a whitelisted column, prefixed with "-" for descending.
 */
abstract class ListRequest extends FormRequest
{
    /** @return list<string> */
    abstract protected function sortable(): array;

    abstract protected function defaultSort(): string;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = [];
        foreach ($this->sortable() as $column) {
            $sorts[] = $column;
            $sorts[] = '-'.$column;
        }

        return array_merge([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in($sorts)],
        ], $this->extraRules());
    }

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [];
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 10);
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search', ''));

        return $search === '' ? null : $search;
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public function sort(): array
    {
        $sort = (string) $this->validated('sort', $this->defaultSort());

        return str_starts_with($sort, '-') ? [substr($sort, 1), 'desc'] : [$sort, 'asc'];
    }

    /**
     * Escapes LIKE wildcards so "50%" searches for the literal text.
     */
    public function likeTerm(): ?string
    {
        $search = $this->search();

        return $search === null ? null : '%'.addcslashes($search, '%_\\').'%';
    }
}
