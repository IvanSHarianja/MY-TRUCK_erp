<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private JournalService $journalService) {}

    /**
     * Auto-generate nomor invoice per company per bulan.
     * Format: INV{YY}{MM}-{NNNN}, contoh: INV2606-0001
     */
    public function generateInvoiceNumber(Company $company, CarbonInterface $date): string
    {
        $prefix = sprintf('INV%02d%02d-', $date->format('y'), $date->format('m'));

        $lastNumber = Invoice::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Default mapping business_unit code → kode akun pendapatan.
     */
    public function defaultRevenueAccountCode(?BusinessUnit $unit): string
    {
        return match (optional($unit)->code) {
            'RENT' => '441100',  // Pendapatan Sewa Alat Berat
            'ARMD' => '441200',  // Pendapatan Ritase Dump Truck
            'MATL' => '441300',  // Pendapatan Penjualan Material
            'BONG' => '441400',  // Pendapatan Borongan Pengurugan
            default => '441900', // Pendapatan Lain-lain
        };
    }

    /**
     * Resolve akun pendapatan (revenue) untuk invoice.
     * Jika user pilih manual → pakai itu. Jika tidak → fallback ke default per lini.
     */
    public function resolveRevenueAccount(Invoice $invoice): Account
    {
        if ($invoice->revenue_account_id) {
            $acc = Account::withoutGlobalScopes()->find($invoice->revenue_account_id);
            if ($acc) return $acc;
        }

        $code = $this->defaultRevenueAccountCode($invoice->businessUnit);

        $acc = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', $code)
            ->first();

        if (! $acc) {
            throw ValidationException::withMessages([
                'revenue_account_id' => "Akun pendapatan {$code} tidak ditemukan di COA. Tambahkan akun atau pilih manual.",
            ]);
        }

        return $acc;
    }

    /**
     * Resolve akun piutang (receivable). Default: 111200 Piutang Usaha.
     */
    public function resolveReceivableAccount(Invoice $invoice): Account
    {
        if ($invoice->receivable_account_id) {
            $acc = Account::withoutGlobalScopes()->find($invoice->receivable_account_id);
            if ($acc) return $acc;
        }

        $acc = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '111200')
            ->first();

        if (! $acc) {
            throw ValidationException::withMessages([
                'receivable_account_id' => 'Akun Piutang Usaha (111200) tidak ditemukan di COA.',
            ]);
        }

        return $acc;
    }

    /**
     * Issue invoice (draft → terbit) dan auto-buat journal:
     *   Dr Piutang Usaha (amount)
     *   Cr Pendapatan Lini (amount)
     */
    public function issue(Invoice $invoice): Invoice
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages([
                'status' => "Hanya invoice DRAFT yang bisa diterbitkan (status sekarang: {$invoice->status}).",
            ]);
        }

        if ((float) $invoice->amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal invoice harus lebih dari 0.',
            ]);
        }

        $revenue = $this->resolveRevenueAccount($invoice);
        $receivable = $this->resolveReceivableAccount($invoice);

        $company = Company::findOrFail($invoice->company_id);
        $invDate = Carbon::parse($invoice->invoice_date);

        $this->journalService->assertPeriodOpen($company, $invDate->year, $invDate->month);

        return DB::transaction(function () use ($invoice, $revenue, $receivable, $company, $invDate) {
            $journal = JournalEntry::create([
                'company_id'       => $invoice->company_id,
                'entry_number'     => $this->journalService->generateEntryNumber($company, $invDate),
                'entry_date'       => $invDate,
                'document_number'  => $invoice->invoice_number,
                'document_type'    => 'invoice',
                'business_unit_id' => $invoice->business_unit_id,
                'description'      => 'Invoice ' . $invoice->invoice_number
                    . ' — ' . optional($invoice->client)->name
                    . ($invoice->description ? ' — ' . $invoice->description : ''),
                'period_year'      => $invDate->year,
                'period_month'     => $invDate->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $invoice->created_by,
                'posted_by'        => Auth::id() ?? $invoice->created_by,
                'posted_at'        => now(),
                'total_amount'     => $invoice->amount,
            ]);

            // Dr Piutang Usaha
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $receivable->id,
                'description'      => 'Piutang dari ' . optional($invoice->client)->name,
                'debit'            => $invoice->amount,
                'kredit'           => 0,
                'sort_order'       => 1,
            ]);

            // Cr Pendapatan
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $revenue->id,
                'description'      => 'Pendapatan dari invoice ' . $invoice->invoice_number,
                'debit'            => 0,
                'kredit'           => $invoice->amount,
                'sort_order'       => 2,
            ]);

            // Re-generate invoice_number kalau masih DRAFT-* placeholder
            $invoiceNumber = $invoice->invoice_number;
            if (str_starts_with((string) $invoiceNumber, 'DRAFT-')) {
                $invoiceNumber = $this->generateInvoiceNumber($company, $invDate);
                $journal->update(['document_number' => $invoiceNumber]);
            }

            $invoice->update([
                'invoice_number'        => $invoiceNumber,
                'status'                => 'terbit',
                'journal_entry_id'      => $journal->id,
                'revenue_account_id'    => $revenue->id,
                'receivable_account_id' => $receivable->id,
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * Void invoice: panggil JournalService::void untuk balik jurnal piutang.
     * Hanya bisa void invoice yang belum ada pembayaran (paid_amount = 0).
     */
    public function void(Invoice $invoice, ?string $reason = null): Invoice
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages([
                'status' => 'Invoice sudah void.',
            ]);
        }

        if ((float) $invoice->paid_amount > 0) {
            throw ValidationException::withMessages([
                'status' => 'Tidak bisa void invoice yang sudah ada pembayarannya. Batalkan pembayaran dulu.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            if ($invoice->journalEntry && $invoice->journalEntry->isPosted()) {
                $this->journalService->void($invoice->journalEntry, $reason);
            }

            $invoice->update([
                'status'      => 'void',
                'voided_by'   => Auth::id(),
                'voided_at'   => now(),
                'void_reason' => $reason,
            ]);

            return $invoice->refresh();
        });
    }
}
