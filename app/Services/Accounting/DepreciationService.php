<?php

namespace App\Services\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Depresiasi bulanan garis lurus (straight line).
 *
 * Formula: monthly_dep = (purchase_price - salvage_value) / useful_life_months
 *
 * Aturan bulan pembelian: TIDAK dihitung. Depresiasi mulai bulan berikutnya
 * setelah purchase_date. (Standar akuntansi Indonesia: mid-month convention
 * bervariasi; kami pilih next-month untuk simplicity dan konsistensi.)
 *
 * Idempotency: cek document_number 'DEP-{asset_id}-{YYYYMM}' — kalau ada,
 * skip aset itu untuk periode itu. User bisa force replay via void manual.
 *
 * Business decision A4 disetujui 2026-07-06: straight line saja untuk MVP.
 * A5: depresiasi tetap jalan meski aset status=maintenance (standar akuntansi).
 * Aset status=non_aktif SKIP (dianggap disposed/retired).
 */
class DepreciationService
{
    public function __construct(private JournalService $journalService) {}

    /**
     * Jalankan depresiasi untuk 1 company + 1 bulan target.
     *
     * @return array{
     *     posted: int,          // jumlah aset yang berhasil di-depresiasi
     *     skipped: int,         // jumlah aset yang di-skip (sudah, belum eligible, fully depreciated)
     *     total_amount: float,  // total nominal depresiasi bulan itu
     *     errors: array<int, string>, // error per aset (tidak fatal)
     * }
     */
    public function runForCompany(Company $company, int $year, int $month): array
    {
        $this->journalService->assertPeriodOpen($company, $year, $month);

        // Tanggal jurnal = akhir bulan target (konvensi umum penyusutan)
        $targetDate = Carbon::create($year, $month, 1)->endOfMonth();

        // Ambil semua aset aktif (skip non_aktif) di company ini
        $assets = Asset::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', ['aktif', 'maintenance'])
            ->get();

        $posted = 0;
        $skipped = 0;
        $totalAmount = 0.0;
        $errors = [];

        foreach ($assets as $asset) {
            try {
                // BUG-DEPUSE-04: return type sekarang float (0.0 = skip, >0 = posted amount).
                // Amount di-report akurat untuk semua method (StraightLine + PerDay),
                // termasuk kalau dicap ke sisa depreciable base.
                $amount = $this->postAssetDepreciation($asset, $company, $targetDate);
                if ($amount > 0) {
                    $posted++;
                    $totalAmount += $amount;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[$asset->id] = "[{$asset->asset_code}] {$e->getMessage()}";
                Log::warning("DepreciationService: gagal depresiasi asset {$asset->asset_code}: {$e->getMessage()}");
            }
        }

        return [
            'posted'       => $posted,
            'skipped'      => $skipped,
            'total_amount' => round($totalAmount, 2),
            'errors'       => $errors,
        ];
    }

    /**
     * Preview: hitung apa yang akan diposting tanpa benar-benar buat jurnal.
     * Dipakai UI/CLI untuk konfirmasi sebelum run.
     *
     * @return array<int, array{asset_id: int, asset_code: string, name: string, monthly: float, eligible: bool, reason: string}>
     */
    public function preview(Company $company, int $year, int $month): array
    {
        $targetDate = Carbon::create($year, $month, 1)->endOfMonth();

        $assets = Asset::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('asset_code')
            ->get();

        $result = [];
        foreach ($assets as $asset) {
            [$eligible, $reason] = $this->checkEligibility($asset, $company, $targetDate);
            $result[] = [
                'asset_id'   => $asset->id,
                'asset_code' => $asset->asset_code,
                'name'       => $asset->name,
                'monthly'    => (float) $asset->monthly_depreciation,
                'eligible'   => $eligible,
                'reason'     => $reason,
            ];
        }

        return $result;
    }

    /**
     * Depresiasi 1 aset untuk 1 bulan target.
     * Return true kalau berhasil post, false kalau skip.
     */
    private function postAssetDepreciation(Asset $asset, Company $company, Carbon $targetDate): float
    {
        [$eligible, $reason] = $this->checkEligibility($asset, $company, $targetDate);
        if (! $eligible) {
            Log::info("DepreciationService: asset {$asset->asset_code} skip untuk {$targetDate->format('Y-m')} — {$reason}");
            return 0.0;
        }

        // BUG-DEPUSE-04: PerDay dihitung time-based: perDay × days_in_month.
        // Straight-line pakai monthly_depreciation accessor seperti biasa.
        $method = $asset->depreciation_method;
        if ($method === DepreciationMethod::PerDay) {
            $perDay = $asset->depreciationPerUnit();
            $monthly = round($perDay * $targetDate->daysInMonth, 2);
        } else {
            $monthly = (float) $asset->monthly_depreciation;
        }

        if ($monthly <= 0) {
            return 0.0;
        }

        // BUG-DEPUSE-05 / HIGH-3 dari audit: cap ke sisa depreciable base.
        // Straight-line rounding 2 dp × N bulan bisa overshoot depreciable base
        // beberapa sen. PerDay lebih rentan (per-hari value × jumlah hari).
        // Cap ini memastikan akumulasi ≤ (purchase_price - salvage_value).
        $depreciableBase = (float) $asset->purchase_price - (float) $asset->salvage_value;
        $accumulated = $this->getAccumulatedDepreciation($asset, $company);
        $remaining = round($depreciableBase - $accumulated, 2);

        if ($remaining <= 0) {
            Log::info("DepreciationService: asset {$asset->asset_code} sudah fully depreciated (akumulasi {$accumulated} >= base {$depreciableBase})");
            return 0.0;
        }

        if ($monthly > $remaining) {
            $monthly = $remaining;
        }

        // Sprint 2.5: role-based lookup dengan fallback code.
        // Beban penyusutan: semua tipe asset pakai role opex_penyusutan (552100).
        // Akumulasi: per tipe (armada/kantor/kendaraan) → role berbeda.
        $accBeban = Account::findByRoleOrCode(
            \App\Enums\AccountRole::OpexPenyusutan,
            $asset->defaultExpenseAccountCode(),
            $company->id,
        );

        $akumulasiRole = match ($asset->defaultAkumulasiCode()) {
            '112105' => \App\Enums\AccountRole::AkumulasiArmada,
            '112115' => \App\Enums\AccountRole::AkumulasiKantor,
            '112125' => \App\Enums\AccountRole::AkumulasiKendaraan,
            default  => \App\Enums\AccountRole::AkumulasiKantor,
        };

        $accAkumulasi = Account::findByRoleOrCode(
            $akumulasiRole,
            $asset->defaultAkumulasiCode(),
            $company->id,
        );

        if (! $accBeban || ! $accAkumulasi) {
            throw new \RuntimeException("Akun beban ({$asset->defaultExpenseAccountCode()}) atau akumulasi ({$asset->defaultAkumulasiCode()}) tidak ditemukan/postable. Set role di master COA atau tambah akun standar.");
        }

        // BU tag dari asset (dump_truck→ARMD, excavator/etc→RENT, kantor→UMUM)
        $bu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $asset->defaultBusinessUnitCode())
            ->first();

        $documentNumber = sprintf('DEP-%d-%04d%02d', $asset->id, $targetDate->year, $targetDate->month);

        // BUG-11: Refactor pakai createEntryWithLines (race-safe entry_number)
        $this->journalService->createEntryWithLines(
            company:          $company,
            date:             $targetDate,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $targetDate,
                'document_number'  => $documentNumber,
                'document_type'    => 'penyusutan',
                'business_unit_id' => optional($bu)->id,
                'description'      => 'Penyusutan bulanan aset ' . $asset->asset_code
                    . ' (' . $asset->name . ') — ' . $targetDate->format('F Y'),
                'period_year'      => $targetDate->year,
                'period_month'     => $targetDate->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? 1,
                'posted_by'        => Auth::id() ?? 1,
                'posted_at'        => now(),
                'total_amount'     => $monthly,
            ],
            linesFactory:     fn (JournalEntry $entry): array => [
                [
                    'account_id'  => $accBeban->id,
                    'asset_id'    => $asset->id,
                    'description' => '[' . $asset->asset_code . '] Beban penyusutan bulanan',
                    'debit'       => $monthly,
                    'kredit'      => 0,
                ],
                [
                    'account_id'  => $accAkumulasi->id,
                    'asset_id'    => $asset->id,
                    'description' => '[' . $asset->asset_code . '] Akumulasi penyusutan',
                    'debit'       => 0,
                    'kredit'      => $monthly,
                ],
            ],
        );

        return $monthly;
    }

    /**
     * Total akumulasi penyusutan yang sudah ter-posted untuk aset tertentu.
     * Sum semua kredit di akun akumulasi (kredit=akumulasi bertambah, debit=reversal).
     *
     * Dipakai untuk cap monthly depreciation ke sisa depreciable base — mencegah
     * akumulasi overshoot `(purchase_price - salvage_value)` gara-gara rounding.
     */
    private function getAccumulatedDepreciation(Asset $asset, Company $company): float
    {
        $akumulasiRole = match ($asset->defaultAkumulasiCode()) {
            '112105' => \App\Enums\AccountRole::AkumulasiArmada,
            '112115' => \App\Enums\AccountRole::AkumulasiKantor,
            '112125' => \App\Enums\AccountRole::AkumulasiKendaraan,
            default  => \App\Enums\AccountRole::AkumulasiKantor,
        };

        $accAkumulasi = Account::findByRoleOrCode(
            $akumulasiRole,
            $asset->defaultAkumulasiCode(),
            $company->id,
        );

        if (! $accAkumulasi) {
            return 0.0;
        }

        // Net: kredit - debit. Hanya line dari jurnal posted (bukan void/draft)
        // dan yang di-tag ke aset ini spesifik.
        $result = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.account_id', $accAkumulasi->id)
            ->where('journal_entry_lines.asset_id', $asset->id)
            ->selectRaw('COALESCE(SUM(kredit), 0) - COALESCE(SUM(debit), 0) AS net')
            ->value('net');

        return (float) $result;
    }

    /**
     * BIZ-03: Post jurnal penyusutan usage-based untuk 1 log usage.
     *
     * Dipanggil dari observer log (RentalLog/RitLog) saat log created/updated.
     * Idempotent: kalau jurnal dengan `documentNumber` sudah ada (posted/draft),
     * langsung return itu — tidak double-post.
     *
     * Cap: usage yang bikin akumulasi melebihi (purchase - salvage) di-clamp
     * ke sisa depreciable base, biar aset tidak "over-depreciated".
     *
     * Return:
     *   - JournalEntry: berhasil di-post (atau existing yang ditemukan)
     *   - null: skip karena kondisi (bukan usage-based, per_unit=0, no sisa,
     *           period closed, dst) — bukan error
     *
     * @param  Asset          $asset         Aset yang di-depresiasi (harus usage-based method)
     * @param  float          $usage         Jumlah unit usage (jam/rit/hari) — dari log
     * @param  Carbon         $date          Tanggal jurnal (biasanya log_date)
     * @param  string         $documentNumber Deterministic doc: 'DEPUSE-{asset_id}-{log_id}'
     * @param  string         $context       Description tambahan (mis. 'RentalLog #5')
     */
    public function postUsageDepreciation(
        Asset $asset,
        float $usage,
        Carbon $date,
        string $documentNumber,
        string $context = '',
    ): ?JournalEntry {
        $method = $asset->depreciation_method;

        if (! $method instanceof DepreciationMethod || ! $method->isUsageBased()) {
            Log::info("postUsageDepreciation skip: asset {$asset->asset_code} method bukan usage-based");
            return null;
        }

        if ($usage <= 0) {
            return null;
        }

        $perUnit = $asset->depreciationPerUnit();
        if ($perUnit <= 0) {
            Log::info("postUsageDepreciation skip: asset {$asset->asset_code} per_unit=0 (useful_life belum diisi atau salvage>=purchase)");
            return null;
        }

        $company = Company::findOrFail($asset->company_id);

        // HIGH-1: Wrap dalam DB::transaction + lockForUpdate pada asset row supaya
        // concurrent DEPUSE untuk aset yang sama di-serialize. Kalau tidak, dua
        // request bareng bisa keduanya baca akumulasi sama → keduanya post amount
        // yang sama → total akumulasi melampaui depreciable base (over-depreciation).
        //
        // Nested transaction OK: createEntryWithLines juga wrap tx, savepoint handle.
        return DB::transaction(function () use ($asset, $usage, $date, $documentNumber, $context, $method, $perUnit, $company) {
            // Lock asset row — force serialize concurrent postUsageDepreciation per asset
            Asset::withoutGlobalScopes()
                ->where('id', $asset->id)
                ->lockForUpdate()
                ->first();

            // Idempotent check — sudah ada jurnal untuk log ini?
            $existing = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('document_number', $documentNumber)
                ->whereIn('status', ['draft', 'posted'])
                ->first();

            if ($existing) {
                return $existing;
            }

            // Cap ke sisa depreciable base — dibaca DALAM lock supaya race-safe
            $currentAkumulasi = $this->getCurrentAkumulasi($asset);
            $depreciableBase = max(0, (float) $asset->purchase_price - (float) $asset->salvage_value);
            $remaining = max(0, $depreciableBase - $currentAkumulasi);

            if ($remaining <= 0) {
                Log::info("postUsageDepreciation skip: asset {$asset->asset_code} sudah fully depreciated");
                return null;
            }

            $rawAmount = round($usage * $perUnit, 2);
            $amount = min($rawAmount, $remaining);
            if ($amount <= 0) {
                return null;
            }

            // Period guard — mengikuti pattern DEP-*
            try {
                $this->journalService->assertPeriodOpen($company, $date->year, $date->month);
            } catch (\Throwable $e) {
                Log::info("postUsageDepreciation skip: periode {$date->year}-{$date->month} closed (asset {$asset->asset_code})");
                return null;
            }

            return $this->postDepuseJournal($asset, $company, $date, $documentNumber, $context, $method, $usage, $perUnit, $amount);
        });
    }

    /**
     * Extract body dari transaction ke method terpisah supaya kompleksitas
     * dalam closure tidak berlebihan. Semua parameter sudah tervalidasi di caller.
     */
    private function postDepuseJournal(
        Asset $asset,
        Company $company,
        Carbon $date,
        string $documentNumber,
        string $context,
        DepreciationMethod $method,
        float $usage,
        float $perUnit,
        float $amount,
    ): ?JournalEntry {

        // Akun beban & akumulasi — sama seperti straight-line
        $accBeban = Account::findByRoleOrCode(
            \App\Enums\AccountRole::OpexPenyusutan,
            $asset->defaultExpenseAccountCode(),
            $company->id,
        );

        $akumulasiRole = match ($asset->defaultAkumulasiCode()) {
            '112105' => \App\Enums\AccountRole::AkumulasiArmada,
            '112115' => \App\Enums\AccountRole::AkumulasiKantor,
            '112125' => \App\Enums\AccountRole::AkumulasiKendaraan,
            default  => \App\Enums\AccountRole::AkumulasiKantor,
        };
        $accAkumulasi = Account::findByRoleOrCode(
            $akumulasiRole,
            $asset->defaultAkumulasiCode(),
            $company->id,
        );

        if (! $accBeban || ! $accAkumulasi) {
            Log::warning("postUsageDepreciation skip: akun beban/akumulasi tidak ditemukan untuk asset {$asset->asset_code}");
            return null;
        }

        $bu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $asset->defaultBusinessUnitCode())
            ->first();

        $unitLabel = $method->unitLabel();
        $description = sprintf(
            'Penyusutan usage aset %s (%s) — %s %s @ Rp %s/%s%s',
            $asset->asset_code,
            $asset->name,
            number_format($usage, ($usage == (int) $usage) ? 0 : 2, ',', '.'),
            $unitLabel,
            number_format($perUnit, 2, ',', '.'),
            $unitLabel,
            $context ? ' — ' . $context : '',
        );

        return $this->journalService->createEntryWithLines(
            company: $company,
            date: $date,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $date,
                'document_number'  => $documentNumber,
                'document_type'    => 'penyusutan',
                'business_unit_id' => optional($bu)->id,
                'description'      => $description,
                'period_year'      => $date->year,
                'period_month'     => $date->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? 1,
                'posted_by'        => Auth::id() ?? 1,
                'posted_at'        => now(),
                'total_amount'     => $amount,
            ],
            linesFactory: fn (JournalEntry $entry): array => [
                [
                    'account_id'  => $accBeban->id,
                    'asset_id'    => $asset->id,
                    'description' => '[' . $asset->asset_code . '] Beban penyusutan usage (' . number_format($usage, 2, ',', '.') . ' ' . $unitLabel . ')',
                    'debit'       => $amount,
                    'kredit'      => 0,
                ],
                [
                    'account_id'  => $accAkumulasi->id,
                    'asset_id'    => $asset->id,
                    'description' => '[' . $asset->asset_code . '] Akumulasi penyusutan usage',
                    'debit'       => 0,
                    'kredit'      => $amount,
                ],
            ],
        );
    }

    /**
     * Akumulasi penyusutan aset s/d saat ini (dari ledger — source of truth).
     * Dipakai internal untuk cap postUsageDepreciation ke sisa depreciable.
     */
    private function getCurrentAkumulasi(Asset $asset): float
    {
        return (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $asset->company_id)
            ->where('je.status', 'posted')
            ->where('a.normal_balance', 'kredit')
            ->where('a.sub_category', 'aset_tetap')
            ->where('jl.asset_id', $asset->id)
            ->sum(DB::raw('jl.kredit - jl.debit'));
    }

    /**
     * Cek eligibility aset untuk depresiasi periode tertentu.
     *
     * @return array{0: bool, 1: string} [eligible, reason]
     */
    private function checkEligibility(Asset $asset, Company $company, Carbon $targetDate): array
    {
        if ($asset->status === 'non_aktif') {
            return [false, 'Status non_aktif (di-retire)'];
        }

        // BIZ-02 / BUG-DEPUSE-04:
        // Cron bulanan proses StraightLine + PerDay (dua-duanya time-based).
        // PerHour + PerRit dipost per log usage via observer — SKIP di sini
        // (kalau tidak di-skip → double-counting: monthly + per-log).
        $method = $asset->depreciation_method;
        if ($method === DepreciationMethod::PerHour || $method === DepreciationMethod::PerRit) {
            return [false, sprintf(
                'Method %s (per %s) — dep di-post per log usage, bukan bulanan',
                $method->value,
                $method->unitLabel(),
            )];
        }

        if (! $asset->purchase_date) {
            return [false, 'Tanggal pembelian belum diisi'];
        }

        // Umur ekonomis check per-method:
        // - StraightLine → useful_life_months
        // - PerDay       → useful_life_days
        $lifeField = $method === DepreciationMethod::PerDay
            ? 'useful_life_days'
            : 'useful_life_months';

        if (! $asset->{$lifeField} || $asset->{$lifeField} <= 0) {
            return [false, "Umur ekonomis ({$lifeField}) belum diisi"];
        }

        $purchaseDate = Carbon::parse($asset->purchase_date);

        // Bulan pembelian tidak dihitung — mulai bulan berikutnya
        $firstDepreciationMonth = $purchaseDate->copy()->addMonthNoOverflow()->startOfMonth();
        $targetMonth = $targetDate->copy()->startOfMonth();

        if ($targetMonth->lt($firstDepreciationMonth)) {
            return [false, "Belum eligible: pembelian {$purchaseDate->format('Y-m')}, depresiasi mulai {$firstDepreciationMonth->format('Y-m')}"];
        }

        // Check umur habis:
        // - StraightLine: pakai bulan (monthsElapsed vs useful_life_months)
        // - PerDay: cap dilakukan via remaining depreciable base di postAssetDepreciation
        //   (tidak bisa akurat cek "days elapsed" bulan berjalan karena hari sudah
        //   di-post di bulan sebelumnya harus diakumulasi)
        if ($method !== DepreciationMethod::PerDay) {
            // BUG-30: Carbon 3 diffInMonths return float. Round untuk hindari
            // off-by-1 di boundary bulan karena timezone/DST drift.
            $monthsElapsed = (int) round($firstDepreciationMonth->diffInMonths($targetMonth)) + 1;
            if ($monthsElapsed > (int) $asset->useful_life_months) {
                return [false, "Fully depreciated (umur ekonomis {$asset->useful_life_months} bulan sudah habis)"];
            }
        }

        // Cek idempotency: sudah ada jurnal untuk aset+periode ini?
        $documentNumber = sprintf('DEP-%d-%04d%02d', $asset->id, $targetDate->year, $targetDate->month);
        $exists = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', $documentNumber)
            ->whereIn('status', ['draft', 'posted'])
            ->exists();

        if ($exists) {
            return [false, "Sudah dipost sebelumnya (doc {$documentNumber})"];
        }

        return [true, 'Eligible'];
    }
}
