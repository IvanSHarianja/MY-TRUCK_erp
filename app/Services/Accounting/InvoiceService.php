<?php

namespace App\Services\Accounting;

use App\Mail\InvoiceIssued;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
     * Kalau parent default sudah HEADER (karena di-split jadi sub-akun),
     * otomatis fallback ke first child postable.
     */
    public function resolveRevenueAccount(Invoice $invoice): Account
    {
        if ($invoice->revenue_account_id) {
            $acc = Account::withoutGlobalScopes()->find($invoice->revenue_account_id);
            if ($acc && $acc->isPostable()) return $acc;
        }

        // Sprint 2.5: role-based mapping BU → role revenue. Fallback ke code klasik.
        $role = match (optional($invoice->businessUnit)->code) {
            'RENT'  => \App\Enums\AccountRole::RevenueRent,
            'ARMD'  => \App\Enums\AccountRole::RevenueArmd,
            'MATL'  => \App\Enums\AccountRole::RevenueMatl,
            'BONG'  => \App\Enums\AccountRole::RevenueBong,
            default => \App\Enums\AccountRole::RevenueLain,
        };

        $code = $this->defaultRevenueAccountCode($invoice->businessUnit);

        $acc = Account::findByRoleOrCode($role, $code, $invoice->company_id);

        if (! $acc) {
            throw ValidationException::withMessages([
                'revenue_account_id' => "Akun pendapatan {$code} tidak ditemukan/postable di COA. "
                    . "Tambahkan sub-akun bila parent sudah jadi HEADER, atau pilih manual.",
            ]);
        }

        return $acc;
    }

    /**
     * Resolve akun piutang (receivable). Default: 111200 Piutang Usaha.
     * Fallback ke first child kalau parent sudah HEADER.
     */
    public function resolveReceivableAccount(Invoice $invoice): Account
    {
        if ($invoice->receivable_account_id) {
            $acc = Account::withoutGlobalScopes()->find($invoice->receivable_account_id);
            if ($acc && $acc->isPostable()) return $acc;
        }

        // Sprint 2.5: role-based (role='receivable_usaha'). Fallback ke code 111200.
        $acc = Account::findByRoleOrCode(
            \App\Enums\AccountRole::ReceivableUsaha,
            '111200',
            $invoice->company_id,
        );

        if (! $acc) {
            throw ValidationException::withMessages([
                'receivable_account_id' => 'Akun Piutang Usaha tidak ditemukan/postable di COA. '
                    . 'Pastikan ada akun dengan role "receivable_usaha" atau kode 111200 yang postable.',
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

        // Resolve asset_id dari source untuk tag revenue line — untuk P&L per unit.
        // Rental contract: 1 kontrak = 1 aset → tag lurus.
        // Armada contract: 1 invoice bisa cover multi-aset (dari multi rit_logs).
        //   Untuk MVP, kita tag asset_id kalau semua rit dalam invoice pakai
        //   1 aset yang sama. Kalau campuran, NULL (revenue tidak ter-tag).
        //   Multi-line split per aset bisa jadi upgrade nanti.
        $revenueAssetId = $this->resolveRevenueAssetId($invoice);

        return DB::transaction(function () use ($invoice, $revenue, $receivable, $company, $invDate, $revenueAssetId) {
            // BUG-15: Lock invoice row + re-check isDraft. Cegah 2 request issue()
            // bareng yang keduanya lolos check di luar tx → double jurnal.
            $lockedInvoice = Invoice::withoutGlobalScopes()
                ->where('id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvoice) {
                throw ValidationException::withMessages([
                    'invoice' => "Invoice tidak ditemukan (id: {$invoice->id}).",
                ]);
            }

            if (! $lockedInvoice->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => "Hanya invoice DRAFT yang bisa diterbitkan (status sekarang: {$lockedInvoice->status}).",
                ]);
            }

            $invoice = $lockedInvoice; // Ganti reference ke locked version

            // BUG-11: Refactor pakai createEntryWithLines yang sudah race-safe
            // (retry pada UniqueConstraintViolationException entry_number).
            $journal = $this->journalService->createEntryWithLines(
                company:          $company,
                date:             $invDate,
                entryDataFactory: fn (string $entryNumber): array => [
                    'company_id'       => $invoice->company_id,
                    'entry_number'     => $entryNumber,
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
                ],
                linesFactory:     fn (JournalEntry $entry): array => [
                    [
                        'account_id'  => $receivable->id,
                        'description' => 'Piutang dari ' . optional($invoice->client)->name,
                        'debit'       => $invoice->amount,
                        'kredit'      => 0,
                    ],
                    [
                        'account_id'  => $revenue->id,
                        'asset_id'    => $revenueAssetId,
                        'description' => 'Pendapatan dari invoice ' . $invoice->invoice_number,
                        'debit'       => 0,
                        'kredit'      => $invoice->amount,
                    ],
                ],
            );

            // BUG-12: Re-generate invoice_number kalau masih DRAFT-* placeholder,
            // dengan retry loop untuk race concurrent (2 draft di bulan sama
            // di-issue bareng → same next number → unique violation).
            $invoiceNumber = $invoice->invoice_number;
            if (str_starts_with((string) $invoiceNumber, 'DRAFT-')) {
                $invoiceNumber = $this->assignUniqueInvoiceNumber($invoice, $company, $invDate);
                $journal->update(['document_number' => $invoiceNumber]);
            }

            $invoice->update([
                'invoice_number'        => $invoiceNumber,
                'status'                => 'terbit',
                'journal_entry_id'      => $journal->id,
                'revenue_account_id'    => $revenue->id,
                'receivable_account_id' => $receivable->id,
            ]);

            $invoice->refresh();

            // Kirim email ke client (opsional - graceful fail kalau SMTP belum dikonfigurasi)
            $this->sendInvoiceEmail($invoice);

            return $invoice;
        });
    }

    /**
     * Resolve asset_id untuk revenue line — dipakai tag P&L per unit.
     *
     * Aturan:
     *   - source_type='rental_contract' → RentalContract.asset_id (1:1)
     *   - source_type='armada_contract' → asset_id kalau semua rit_logs
     *     dalam invoice pakai 1 aset yang sama, kalau campuran → NULL
     *     (multi-line split per aset bisa jadi upgrade nanti)
     *   - source_type='material_sale', 'project_termin' → NULL (tidak related aset)
     *   - Manual invoice tanpa source → NULL
     */
    public function resolveRevenueAssetId(Invoice $invoice): ?int
    {
        if (! $invoice->source_type || ! $invoice->source_id) {
            return null;
        }

        if ($invoice->source_type === 'rental_contract') {
            // BUG-35: explicit company scope (defense-in-depth)
            $contract = \App\Models\RentalContract::withoutGlobalScopes()
                ->where('company_id', $invoice->company_id)
                ->find($invoice->source_id);
            return $contract?->asset_id;
        }

        if ($invoice->source_type === 'armada_contract') {
            // Cari distinct asset_id dari rit_logs yang sudah di-link ke invoice ini.
            // Kalau hanya 1 asset unique → tag itu. Kalau lebih → NULL (mixed).
            // BUG-35: explicit company scope
            $assetIds = \App\Models\RitLog::withoutGlobalScopes()
                ->where('company_id', $invoice->company_id)
                ->where('invoice_id', $invoice->id)
                ->distinct()
                ->pluck('asset_id')
                ->filter()
                ->all();

            return count($assetIds) === 1 ? (int) $assetIds[0] : null;
        }

        // material_sale, project_termin, atau manual → tidak related aset spesifik
        return null;
    }

    /**
     * Kirim email invoice ke client (tidak crash kalau SMTP gagal).
     */
    private function sendInvoiceEmail(Invoice $invoice): void
    {
        $clientEmail = optional($invoice->client)->email;
        if (! $clientEmail || ! filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            return;  // Skip kalau client tidak punya email
        }

        try {
            Mail::to($clientEmail)->send(new InvoiceIssued($invoice));
        } catch (\Throwable $e) {
            // Log error tapi jangan rollback invoice
            Log::warning('Gagal kirim email invoice ' . $invoice->invoice_number . ': ' . $e->getMessage());
        }
    }

    /**
     * BUG-12: Assign invoice_number dengan retry-on-collision.
     *
     * Race scenario: 2 draft invoice (dari 2 kontrak berbeda) di-issue bareng
     * di bulan sama → generateInvoiceNumber() dua-duanya dapat INVYYYYMM-NNNN
     * yang sama → unique constraint violation saat update.
     *
     * Fix: catch UniqueConstraintViolationException, regenerate nomor
     * (yang sekarang akan dapat +1 dari yang barusan commit).
     */
    private function assignUniqueInvoiceNumber(
        Invoice $invoice,
        Company $company,
        CarbonInterface $date,
        int $maxAttempts = 5,
    ): string {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $number = $this->generateInvoiceNumber($company, $date);
                $invoice->update(['invoice_number' => $number]);
                return $number;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $lastException = $e;
                // Exponential backoff + jitter
                usleep(random_int(10_000 * (2 ** ($attempt - 1)), 10_000 * (2 ** $attempt) + 40_000));
            }
        }

        throw $lastException ?? new \RuntimeException(
            "Gagal generate invoice_number unik setelah {$maxAttempts} percobaan."
        );
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
