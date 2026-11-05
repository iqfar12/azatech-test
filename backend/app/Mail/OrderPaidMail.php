<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Sent synchronously from inside ProcessPaidOrder (the job is the async boundary).
 */
final class OrderPaidMail extends Mailable
{
    public function __construct(
        public readonly Order $order,
        private readonly string $invoiceHtml,
        private readonly string $invoiceFilename,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pembayaran diterima — Invoice {$this->order->order_number}");
    }

    public function headers(): Headers
    {
        // Deterministic Message-ID → receiving systems can drop an accidental duplicate.
        return new Headers(messageId: "invoice-{$this->order->order_number}@azatech.local");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-paid', with: ['order' => $this->order]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->invoiceHtml, $this->invoiceFilename)->withMime('text/html'),
        ];
    }
}
