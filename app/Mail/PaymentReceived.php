<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Konfirmasi Pembayaran - {$this->payment->payment_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-received',
            with: [
                'payment' => $this->payment->load(['invoice.client', 'cashAccount']),
            ],
        );
    }
}
