<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $companyName = optional($this->invoice->company)->name ?? 'MY-TRUCK';
        return new Envelope(
            subject: "Invoice #{$this->invoice->invoice_number} - {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice-issued',
            with: [
                'invoice' => $this->invoice->load(['client', 'businessUnit', 'company']),
            ],
        );
    }
}
