<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\LowStockAlert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LowStockAlertMail extends Mailable
{
    public function __construct(public readonly LowStockAlert $alert)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Stok menipis: {$this->alert->product->product_name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.low-stock', with: ['alert' => $this->alert]);
    }
}
