<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductIndexRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products)
    {
    }

    /**
     * List products (pagination, search on code/name, sort).
     */
    public function index(ProductIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);
        [$column, $direction] = $request->sort();
        $like = $request->likeTerm();

        $paginator = Product::query()
            ->when($like !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('product_name', 'ilike', $like)
                ->orWhere('product_code', 'ilike', $like)))
            ->when($request->boolean('low_stock'), fn (Builder $q) => $q->whereColumn('stock', '<', 'low_stock_threshold'))
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return ApiResponse::paginated($paginator, ProductResource::class);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        /** @var array{product_code: string, product_name: string, price: string, low_stock_threshold?: int, stock?: int} $data */
        $data = $request->validated();
        $product = $this->products->create($data, $this->actor($request));

        return ApiResponse::success(new ProductResource($product), 'Produk berhasil dibuat', 201);
    }

    public function show(Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        return ApiResponse::success(new ProductResource($product));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->products->update($product, $request->validated());

        return ApiResponse::success(new ProductResource($product), 'Produk berhasil diperbarui');
    }

    /**
     * Soft delete. Past orders keep their snapshot of code/name/price.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('delete', $product);
        $this->products->delete($product, $this->actor($request));

        return ApiResponse::noContent();
    }
}
