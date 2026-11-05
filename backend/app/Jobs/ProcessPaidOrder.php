<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Order\OrderStatus;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Services\InvoiceRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Builds the invoice and e-mails it to the customer after an order becomes PAID.
 *
 * Safe to run more than once:
 *  - ShouldBeUnique   → only one copy of the job for an order can be queued at a time;
 *  - WithoutOverlapping → two workers never execute it for the same order concurrently;
 *  - invoice_sent_at  → a re-run (manual retry, duplicate dispatch) is a no-op once sent.
 *
 * Remaining window: a crash after the SMTP server accepted the mail but before
 * invoice_sent_at is saved causes one duplicate on retry (at-least-once delivery).
 * The Message-ID is deterministic per order so mail systems can de-duplicate it.
 */
final class ProcessPaidOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $orderId)
    {
        $this->onQueue('notifications');
    }

    /**
     * Exponential-ish backoff between attempts (seconds).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90, 300];
    }

    public function uniqueId(): string
    {
        return 'order-paid:'.$this->orderId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('order-paid:'.$this->orderId))->releaseAfter(15)->expireAfter(120)];
    }

    public function handle(InvoiceRenderer $renderer): void
    {
        $order = Order::query()->with(['customer', 'items', 'creator'])->find($this->orderId);

        if ($order === null || $order->invoice_sent_at !== null) {
            return;
        }

        // PAID → CANCELLED may happen before the worker picks the job up.
        if (! in_array($order->status, OrderStatus::revenueStatuses(), true)) {
            Log::info('invoice.skipped', ['order_id' => $order->id, 'status' => $order->status->value]);

            return;
        }

        $html = $renderer->render($order);

        if ($order->customer->email !== null && $order->customer->email !== '') {
            Mail::to($order->customer->email, $order->customer->customer_name)
                ->send(new OrderPaidMail($order, $html, $renderer->filename($order)));
        }

        $order->forceFill(['invoice_sent_at' => CarbonImmutable::now()])->saveQuietly();

        Log::info('invoice.sent', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'emailed' => $order->customer->email !== null,
        ]);
    }

    /**
     * Called once all retries are exhausted. The job is also stored in failed_jobs
     * and can be retried with `php artisan queue:retry <uuid>`.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('invoice.failed_permanently', [
            'order_id' => $this->orderId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
