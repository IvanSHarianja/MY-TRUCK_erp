<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceOverdueReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "REMINDER: Invoice #{$this->invoice->invoice_number} sudah jatuh tempo",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice-overdue',
            with: [
                'invoice' => $this->invoice->load(['client', 'businessUnit', 'company']),
                'umurHari' => (int) $this->invoice->umur_hari,
            ],
        );
    }
}
