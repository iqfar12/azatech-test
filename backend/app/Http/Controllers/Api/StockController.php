<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StockAdjustmentRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class StockController extends Controller
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * Manual stock adjustment (stock opname), admin only.
     */
    public function adjust(StockAdjustmentRequest $request, Product $product): JsonResponse
    {
        $movement = $this->stock->adjust(
            $product,
            (int) $request->validated('quantity_change'),
            (string) $request->validated('note'),
            $this->actor($request),
        );

        $movement->load('creator');

        return ApiResponse::success([
            'movement' => (new StockMovementResource($movement))->resolve($request),
            'product' => (new ProductResource($product->refresh()))->resolve($request),
        ], 'Stok berhasil disesuaikan', 201);
    }

    /**
     * Ledger history of one product, newest first.
     */
    public function movements(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('viewStockMovements', $product);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'in:INITIAL,ORDER_OUT,CANCEL_RESTORE,ADJUSTMENT'],
        ]);

        $paginator = $product->stockMovements()
            ->with('creator')
            ->when(isset($validated['type']), fn ($q) => $q->where('type', $validated['type']))
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return ApiResponse::paginated($paginator, StockMovementResource::class);
    }
}
