<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Order\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use Illuminate\Support\Facades\View;

/**
 * Renders a self-contained HTML invoice. Used by both the download endpoint and
 * the e-mail job, so the customer and the cashier always see the same document.
 */
final class InvoiceRenderer
{
    public function render(Order $order): string
    {
        if (! in_array($order->status, OrderStatus::revenueStatuses(), true)) {
            throw new BusinessRuleException(
                'Invoice hanya tersedia untuk order yang sudah dibayar',
                409,
                ['status' => ["Status order saat ini {$order->status->value}"]],
            );
        }

        $order->loadMissing(['customer', 'items', 'creator']);

        return View::make('invoices.order', [
            'order' => $order,
            'timezone' => (string) config('shop.business_timezone'),
        ])->render();
    }

    public function filename(Order $order): string
    {
        return "invoice-{$order->order_number}.html";
    }
}
