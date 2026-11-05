<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UserRole;
use App\Mail\LowStockAlertMail;
use App\Models\LowStockAlert;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifies admins when a product's stock drops below its low_stock_threshold.
 *
 * Writes a row in low_stock_alerts (the admin notification table, unique per stock
 * movement) and e-mails all admins once. Re-runs are no-ops thanks to emailed_at.
 */
final class SendLowStockAlert implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $productId, public int $stockMovementId)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90, 300];
    }

    public function uniqueId(): string
    {
        return 'low-stock:'.$this->stockMovementId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('low-stock:'.$this->stockMovementId))->releaseAfter(10)->expireAfter(60)];
    }

    public function handle(): void
    {
        $movement = StockMovement::query()->with('product')->find($this->stockMovementId);

        if ($movement === null) {
            return;
        }

        // createOrFirst relies on the unique index (stock_movement_id) → race-free.
        $alert = LowStockAlert::query()->createOrFirst(
            ['stock_movement_id' => $movement->id],
            [
                'product_id' => $this->productId,
                'stock_at_alert' => $movement->stock_after,
                'threshold' => $movement->product->low_stock_threshold,
            ],
        );

        if ($alert->emailed_at !== null) {
            return;
        }

        $admins = User::query()->where('role', UserRole::Admin)->pluck('email')->all();

        if ($admins !== []) {
            Mail::to($admins)->send(new LowStockAlertMail($alert->load('product')));
        }

        $alert->forceFill(['emailed_at' => CarbonImmutable::now()])->save();

        Log::info('stock.low_alert_sent', ['product_id' => $this->productId, 'stock' => $movement->stock_after]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('stock.low_alert_failed', [
            'product_id' => $this->productId,
            'stock_movement_id' => $this->stockMovementId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
