<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Http\Requests\ListRequest;

final class ProductIndexRequest extends ListRequest
{
    protected function sortable(): array
    {
        return ['product_code', 'product_name', 'price', 'stock', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'product_code';
    }

    protected function extraRules(): array
    {
        return ['low_stock' => ['sometimes', 'boolean']];
    }
}
