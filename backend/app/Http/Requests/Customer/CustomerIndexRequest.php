<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Http\Requests\ListRequest;

final class CustomerIndexRequest extends ListRequest
{
    protected function sortable(): array
    {
        return ['customer_name', 'birth_date', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'customer_name';
    }
}
