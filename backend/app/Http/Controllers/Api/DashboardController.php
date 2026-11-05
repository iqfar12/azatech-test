<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LowStockAlert;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /**
     * Dashboard summary (cached in Redis, invalidated after every relevant write).
     */
    public function summary(): JsonResponse
    {
        return ApiResponse::success($this->dashboard->summary());
    }

    /**
     * Latest low-stock alerts (admin notification table).
     */
    public function lowStockAlerts(Request $request): JsonResponse
    {
        Gate::authorize('view-low-stock-alerts');

        $alerts = LowStockAlert::query()
            ->with('product')
            ->orderByDesc('id')
            ->limit(min(50, max(1, (int) $request->query('limit', '20'))))
            ->get()
            ->map(fn (LowStockAlert $alert): array => [
                'id' => $alert->id,
                'product_id' => $alert->product_id,
                'product_code' => $alert->product->product_code,
                'product_name' => $alert->product->product_name,
                'current_stock' => $alert->product->stock,
                'stock_at_alert' => $alert->stock_at_alert,
                'threshold' => $alert->threshold,
                'emailed' => $alert->emailed_at !== null,
                'created_at' => $alert->created_at->toIso8601String(),
            ]);

        return ApiResponse::success($alerts->all());
    }
}
