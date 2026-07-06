<?php

namespace App\Services\Accounting;

use App\Enums\QuickTransactionType;
use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Service untuk halaman "Transaksi & Beban Terpadu".
 *
 * Resolve mapping enum → akun fixed (via Account::findPostableByCode),
 * gabung dengan counterAccount yang user pilih, generate JournalEntry posted
 * dengan document_type='quick_tx' supaya bisa difilter di table.
 */
class QuickTransactionService
{
    public function __construct(
        protected JournalService $journalService,
    ) {}

    /**
     * Post quick transaction → return JournalEntry yang sudah posted.
     *
     * Validasi yang dilakukan:
     * - amount > 0
     * - counterAccount: milik tenant + postable + match allowed set per (type, method)
     * - businessUnit: milik tenant (jika dikirim)
     * - period: belum closed
     * - fixed account: ada & postable di COA tenant
     */
    public function post(
        Company $company,
        QuickTransactionType $type,
        Account $counterAccount,
        float $amount,
        ?CarbonInterface $date = null,
        ?int $businessUnitId = null,
        ?string $description = null,
        ?string $method = null,
    ): JournalEntry {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal harus lebih dari 0.',
            ]);
        }

        $txDate = $date ? Carbon::parse($date) : Carbon::today();
        $this->journalService->assertPeriodOpen($company, $txDate->year, $txDate->month);

        // === Tenant scope validation: counterAccount ===
        if ($counterAccount->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'counter_account_id' => 'Akun tidak valid untuk tenant ini.',
            ]);
        }
        if (! $counterAccount->isPostable()) {
            throw ValidationException::withMessages([
                'counter_account_id' => "Akun [{$counterAccount->code}] {$counterAccount->name} adalah HEADER. Pilih sub-akun spesifik.",
            ]);
        }

        // === Allowed-set validation: counterAccount harus match opsi yang valid untuk (type, method) ===
        // Cegah malicious payload (e.g. user kirim akun 1-101 untuk BebanPenyusutan)
        $resolvedMethod = $method ?: $this->inferMethodFromAccount($type, $counterAccount);
        if (! in_array($resolvedMethod, $type->allowedMethods(), true)) {
            throw ValidationException::withMessages([
                'method' => "Metode '{$resolvedMethod}' tidak diizinkan untuk transaksi {$type->label()}.",
            ]);
        }

        $allowedAccountIds = $this->counterAccountOptions($company, $type, $resolvedMethod)
            ->pluck('id')
            ->all();

        if (! in_array($counterAccount->id, $allowedAccountIds, true)) {
            throw ValidationException::withMessages([
                'counter_account_id' => "Akun [{$counterAccount->code}] {$counterAccount->name} tidak valid sebagai akun lawan untuk transaksi {$type->label()}. "
                    . 'Pilih dari daftar yang tersedia.',
            ]);
        }

        // === Tenant scope validation: businessUnit ===
        $businessUnit = null;
        if ($businessUnitId) {
            $businessUnit = BusinessUnit::query()
                ->where('company_id', $company->id)
                ->find($businessUnitId);

            if (! $businessUnit) {
                throw ValidationException::withMessages([
                    'business_unit_id' => 'Lini bisnis tidak valid untuk tenant ini.',
                ]);
            }
        } else {
            $businessUnit = BusinessUnit::query()
                ->where('company_id', $company->id)
                ->where('code', 'UMUM')
                ->first();
        }

        // === Beban HPP wajib dialokasikan ke lini bisnis operasional (bukan UMUM) ===
        // Alasan: HPP masuk ke Laba Kotor per lini bisnis. Kalau di-tag UMUM,
        // Income Statement Matrix per lini jadi kelihatan lebih untung dari kenyataan.
        if ($type->isBebanHpp() && $businessUnit && $businessUnit->code === 'UMUM') {
            throw ValidationException::withMessages([
                'business_unit_id' => "Beban {$type->label()} adalah HPP dan harus dialokasikan "
                    . 'ke lini bisnis operasional (RENT / ARMD / MATL / BONG), bukan UMUM.',
            ]);
        }

        // === Resolve fixed account dari enum ===
        $fixedCode = $type->fixedAccountCode();
        $fixedAccount = Account::findPostableByCode($fixedCode, $company->id);
        if (! $fixedAccount) {
            throw ValidationException::withMessages([
                'type' => "Akun [{$fixedCode}] yang dibutuhkan untuk {$type->label()} tidak ditemukan / tidak postable. "
                    . 'Pastikan COA sudah ter-sync atau tambah sub-akun bila sudah jadi HEADER.',
            ]);
        }

        // === Build Dr/Cr berdasarkan fixedSide ===
        [$drAccountId, $crAccountId] = match ($type->fixedSide()) {
            'debit'  => [$fixedAccount->id, $counterAccount->id],
            'kredit' => [$counterAccount->id, $fixedAccount->id],
        };

        $desc = $description !== null && trim($description) !== ''
            ? trim($description)
            : $type->label();

        // Race-safe: createEntryWithLines retry pada unique constraint violation
        // bila entry_number bentrok dengan request concurrent.
        return $this->journalService->createEntryWithLines(
            company:           $company,
            date:              $txDate,
            entryDataFactory:  fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $txDate,
                'document_number'  => $this->generateDocumentNumber($company, $type, $txDate),
                'document_type'    => 'quick_tx',
                'business_unit_id' => optional($businessUnit)->id,
                'description'      => $desc,
                'period_year'      => $txDate->year,
                'period_month'     => $txDate->month,
                'status'           => 'posted',
                'created_by'       => Auth::id(),
                'posted_by'        => Auth::id(),
                'posted_at'        => now(),
                'total_amount'     => $amount,
            ],
            linesFactory:      fn (JournalEntry $entry): array => [
                [
                    'account_id'  => $drAccountId,
                    'description' => $desc,
                    'debit'       => $amount,
                    'kredit'      => 0,
                ],
                [
                    'account_id'  => $crAccountId,
                    'description' => $desc,
                    'debit'       => 0,
                    'kredit'      => $amount,
                ],
            ],
        );
    }

    /**
     * Generate document_number deterministic: {PREFIX}{YY}{MM}-{NNNN}
     * Contoh: BBK2606-0001, BKM2606-0042, JM2606-0007
     *
     * Counter per (company, prefix, year-month) — atomic via withoutGlobalScopes scan.
     */
    public function generateDocumentNumber(Company $company, QuickTransactionType $type, CarbonInterface $date): string
    {
        $prefix = sprintf('%s%02d%02d-', $type->documentPrefix(), $date->format('y'), $date->format('m'));

        $last = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', 'like', $prefix . '%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve akun-akun valid untuk counterAccount picker, sesuai metode yang dipilih.
     *
     * - kas/bank → akun aset lancar dengan cash_flow_category='operasi' (postable)
     * - utang    → akun kewajiban_lancar (postable) — biasanya 221100 Utang Usaha Vendor
     * - nonkas   → akun akumulasi penyusutan (untuk penyusutan)
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    public function counterAccountOptions(
        Company $company,
        QuickTransactionType $type,
        string $method,
    ) {
        $query = Account::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->postable();

        if ($type->isPenyusutan()) {
            // Akumulasi penyusutan: 112105/112115/112125 (sesuai seed COA)
            $codes = array_keys(QuickTransactionType::akumulasiPenyusutanOptions());
            return $query->whereIn('code', $codes)->orderBy('code')->get();
        }

        return match ($method) {
            'kas', 'bank' => $query
                ->where('category', 'aset')
                ->where('sub_category', 'aset_lancar')
                ->where('cash_flow_category', 'operasi')
                ->orderBy('code')
                ->get(),

            'utang' => $query
                ->where('category', 'kewajiban')
                ->where('sub_category', 'kewajiban_lancar')
                ->where('code', 'like', '2211%')
                ->orderBy('code')
                ->get(),

            default => $query->whereRaw('1=0')->get(),
        };
    }

    /**
     * Fallback: infer method dari struktur counterAccount kalau form tidak kirim method.
     * Dipakai untuk defensive validation.
     */
    protected function inferMethodFromAccount(QuickTransactionType $type, Account $counter): string
    {
        if ($type->isPenyusutan()) return 'nonkas';
        if ($counter->category === 'kewajiban') return 'utang';
        return 'kas'; // default — toh 'kas' dan 'bank' sama-sama mengambil aset lancar operasi
    }
}
