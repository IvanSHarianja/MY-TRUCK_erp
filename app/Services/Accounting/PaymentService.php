<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private JournalService $journalService,
        private InvoiceService $invoiceService,
    ) {}

    /**
     * Auto-generate nomor payment per company per bulan.
     * Format: PAY{YY}{MM}-{NNNN}, contoh: PAY2606-0001
     */
    public function generatePaymentNumber(Company $company, CarbonInterface $date): string
    {
        $prefix = sprintf('PAY%02d%02d-', $date->format('y'), $date->format('m'));

        $lastNumber = Payment::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('payment_number', 'like', $prefix . '%')
            ->orderByDesc('payment_number')
            ->value('payment_number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Terima pembayaran untuk invoice. Auto-buat journal:
     *   Dr Kas/Bank (cash_account_id)
     *   Cr Piutang Usaha
     *
     * Mendukung pembayaran sebagian. Status invoice:
     *   - paid_amount < amount → 'sebagian'
     *   - paid_amount == amount → 'lunas'
     */
    public function pay(
        Invoice $invoice,
        Account $cashAccount,
        float $amount,
        ?CarbonInterface $paymentDate = null,
        ?string $referenceNumber = null,
        ?string $description = null,
    ): Payment {
        if (! $invoice->canReceivePayment()) {
            throw ValidationException::withMessages([
                'invoice' => "Invoice {$invoice->invoice_number} tidak bisa menerima pembayaran (status: {$invoice->status}).",
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pembayaran harus lebih dari 0.',
            ]);
        }

        $sisa = (float) $invoice->amount - (float) $invoice->paid_amount;
        if (round($amount, 2) > round($sisa, 2)) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Nominal pembayaran (Rp %s) melebihi sisa piutang (Rp %s).',
                    number_format($amount, 0, ',', '.'),
                    number_format($sisa, 0, ',', '.'),
                ),
            ]);
        }

        $payDate = $paymentDate ? Carbon::parse($paymentDate) : Carbon::today();
        $company = Company::findOrFail($invoice->company_id);

        $this->journalService->assertPeriodOpen($company, $payDate->year, $payDate->month);

        $receivable = $this->invoiceService->resolveReceivableAccount($invoice);

        return DB::transaction(function () use (
            $invoice, $cashAccount, $amount, $payDate, $referenceNumber,
            $description, $company, $receivable
        ) {
            $paymentNumber = $this->generatePaymentNumber($company, $payDate);

            $journal = JournalEntry::create([
                'company_id'       => $invoice->company_id,
                'entry_number'     => $this->journalService->generateEntryNumber($company, $payDate),
                'entry_date'       => $payDate,
                'document_number'  => $paymentNumber,
                'document_type'    => 'bkm',  // Bukti Kas Masuk
                'business_unit_id' => $invoice->business_unit_id,
                'description'      => 'Penerimaan pembayaran ' . $invoice->invoice_number
                    . ' — ' . optional($invoice->client)->name
                    . ($description ? ' — ' . $description : ''),
                'period_year'      => $payDate->year,
                'period_month'     => $payDate->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $invoice->created_by,
                'posted_by'        => Auth::id() ?? $invoice->created_by,
                'posted_at'        => now(),
                'total_amount'     => $amount,
            ]);

            // Dr Kas/Bank
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $cashAccount->id,
                'description'      => 'Penerimaan ' . $invoice->invoice_number,
                'debit'            => $amount,
                'kredit'           => 0,
                'sort_order'       => 1,
            ]);

            // Cr Piutang Usaha
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $receivable->id,
                'description'      => 'Pelunasan piutang ' . optional($invoice->client)->name,
                'debit'            => 0,
                'kredit'           => $amount,
                'sort_order'       => 2,
            ]);

            $payment = Payment::create([
                'company_id'       => $invoice->company_id,
                'payment_number'   => $paymentNumber,
                'payment_date'     => $payDate,
                'invoice_id'       => $invoice->id,
                'cash_account_id'  => $cashAccount->id,
                'amount'           => $amount,
                'reference_number' => $referenceNumber,
                'description'      => $description,
                'journal_entry_id' => $journal->id,
                'created_by'       => Auth::id() ?? $invoice->created_by,
            ]);

            $newPaid = (float) $invoice->paid_amount + $amount;
            $newStatus = round($newPaid, 2) >= round((float) $invoice->amount, 2)
                ? 'lunas'
                : 'sebagian';

            $invoice->update([
                'paid_amount' => $newPaid,
                'status'      => $newStatus,
            ]);

            return $payment;
        });
    }

    /**
     * Batalkan pembayaran: void journal entry-nya dan kurangi paid_amount invoice.
     */
    public function reverse(Payment $payment, ?string $reason = null): void
    {
        DB::transaction(function () use ($payment, $reason) {
            if ($payment->journalEntry && $payment->journalEntry->isPosted()) {
                $this->journalService->void($payment->journalEntry, $reason);
            }

            $invoice = $payment->invoice;
            $newPaid = max(0, (float) $invoice->paid_amount - (float) $payment->amount);
            $newStatus = $newPaid <= 0 ? 'terbit' : 'sebagian';

            $invoice->update([
                'paid_amount' => $newPaid,
                'status'      => $newStatus,
            ]);

            $payment->delete();
        });
    }
}
