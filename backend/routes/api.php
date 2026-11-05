<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

/*
 * Every route is prefixed with /api (bootstrap/app.php). Authorization is enforced
 * per action by Policies / Gates (see app/Policies), never by route visibility alone.
 */

Route::get('health', HealthController::class)->name('health');

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::apiResource('products', ProductController::class);
    Route::post('products/{product}/stock-adjustments', [StockController::class, 'adjust'])->name('products.stock-adjustments');
    Route::get('products/{product}/stock-movements', [StockController::class, 'movements'])->name('products.stock-movements');

    Route::apiResource('customers', CustomerController::class);

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders')->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
    Route::post('orders/{order}/ship', [OrderController::class, 'ship'])->name('orders.ship');
    Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->name('orders.complete');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');

    Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    Route::get('notifications/low-stock', [DashboardController::class, 'lowStockAlerts'])->name('notifications.low-stock');

    Route::get('reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
    Route::get('reports/top-products', [ReportController::class, 'topProducts'])->name('reports.top-products');
});
