<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportRequest;
use App\Http\Responses\ApiResponse;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

final class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /**
     * Orders and revenue per day/month (PAID, SHIPPED, COMPLETED only). Admin only.
     */
    public function sales(ReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->reports->sales(
            (string) $request->validated('from'),
            (string) $request->validated('to'),
            (string) $request->validated('group_by', 'day'),
        ));
    }

    /**
     * Best-selling products by quantity (default) or revenue. Admin only.
     */
    public function topProducts(ReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->reports->topProducts(
            (string) $request->validated('from'),
            (string) $request->validated('to'),
            (int) $request->validated('limit', 10),
            (string) $request->validated('sort_by', 'quantity'),
        ));
    }
}
