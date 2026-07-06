<?php

namespace App\Services\Accounting;

use App\Mail\PaymentReceived;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        // Validasi cash account yg user pilih harus postable (bukan HEADER)
        if (! $cashAccount->isPostable()) {
            throw ValidationException::withMessages([
                'cash_account_id' => "Akun [{$cashAccount->code}] {$cashAccount->name} adalah HEADER (punya sub-akun). Pilih sub-akun spesifik.",
            ]);
        }

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

            // Kirim email konfirmasi pembayaran ke client (graceful fail)
            $this->sendPaymentEmail($payment);

            return $payment;
        });
    }

    private function sendPaymentEmail(Payment $payment): void
    {
        $clientEmail = optional($payment->invoice->client)->email;
        if (! $clientEmail || ! filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::to($clientEmail)->send(new PaymentReceived($payment));
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim email konfirmasi payment ' . $payment->payment_number . ': ' . $e->getMessage());
        }
    }

    /**
     * Batalkan pembayaran: void journal entry-nya dan kurangi paid_amount invoice.
     *
     * Edge case yang di-handle defensif (log warning, tidak throw):
     * - payment->journalEntry null (data anomali, seharusnya selalu ada dari pay()).
     * - journalEntry sudah void di luar payment flow (mis. via journal admin).
     *   Dalam kasus itu, cash entry sudah reversed, tapi status invoice masih
     *   'lunas'/'sebagian'. reverse() akan sinkronkan status ke kondisi tanpa
     *   payment ini — mencegah drift antara data invoice dan buku besar.
     */
    public function reverse(Payment $payment, ?string $reason = null): void
    {
        DB::transaction(function () use ($payment, $reason) {
            if (! $payment->journalEntry) {
                Log::warning("Payment {$payment->payment_number} tidak punya journalEntry saat di-reverse. "
                    . 'Kemungkinan data anomali dari import atau bug sebelumnya.');
            } elseif ($payment->journalEntry->isPosted()) {
                $this->journalService->void($payment->journalEntry, $reason);
            } else {
                // Journal sudah void sebelumnya (mis. via journal admin) —
                // skip void, tapi tetap sinkronkan invoice status.
                Log::info("Payment {$payment->payment_number} di-reverse tapi journal "
                    . "{$payment->journalEntry->entry_number} sudah tidak POSTED "
                    . "(status: {$payment->journalEntry->status}). Skip void jurnal.");
            }

            $invoice = $payment->invoice;
            $newPaid = max(0, (float) $invoice->paid_amount - (float) $payment->amount);
            $newStatus = round($newPaid, 2) <= 0 ? 'terbit' : 'sebagian';

            $invoice->update([
                'paid_amount' => $newPaid,
                'status'      => $newStatus,
            ]);

            $payment->delete();
        });
    }
}
