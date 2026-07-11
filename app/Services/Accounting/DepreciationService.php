<?php

namespace App\Services\Accounting;

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
                if ($this->postAssetDepreciation($asset, $company, $targetDate)) {
                    $posted++;
                    $totalAmount += (float) $asset->monthly_depreciation;
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
    private function postAssetDepreciation(Asset $asset, Company $company, Carbon $targetDate): bool
    {
        [$eligible, $reason] = $this->checkEligibility($asset, $company, $targetDate);
        if (! $eligible) {
            Log::info("DepreciationService: asset {$asset->asset_code} skip untuk {$targetDate->format('Y-m')} — {$reason}");
            return false;
        }

        $monthly = (float) $asset->monthly_depreciation;
        if ($monthly <= 0) {
            return false;
        }

        // Resolve akun
        $accBeban = Account::findPostableByCode($asset->defaultExpenseAccountCode(), $company->id);
        $accAkumulasi = Account::findPostableByCode($asset->defaultAkumulasiCode(), $company->id);

        if (! $accBeban || ! $accAkumulasi) {
            throw new \RuntimeException("Akun beban ({$asset->defaultExpenseAccountCode()}) atau akumulasi ({$asset->defaultAkumulasiCode()}) tidak ditemukan/postable.");
        }

        // BU tag dari asset (dump_truck→ARMD, excavator/etc→RENT, kantor→UMUM)
        $bu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $asset->defaultBusinessUnitCode())
            ->first();

        $documentNumber = sprintf('DEP-%d-%04d%02d', $asset->id, $targetDate->year, $targetDate->month);

        DB::transaction(function () use ($asset, $company, $targetDate, $monthly, $accBeban, $accAkumulasi, $bu, $documentNumber) {
            $journal = JournalEntry::create([
                'company_id'       => $company->id,
                'entry_number'     => $this->journalService->generateEntryNumber($company, $targetDate),
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
            ]);

            \App\Models\JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $accBeban->id,
                'description'      => 'Beban penyusutan ' . $asset->asset_code,
                'debit'            => $monthly,
                'kredit'           => 0,
                'sort_order'       => 1,
            ]);

            \App\Models\JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $accAkumulasi->id,
                'description'      => 'Akumulasi penyusutan ' . $asset->asset_code,
                'debit'            => 0,
                'kredit'           => $monthly,
                'sort_order'       => 2,
            ]);
        });

        return true;
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

        if (! $asset->purchase_date) {
            return [false, 'Tanggal pembelian belum diisi'];
        }

        if (! $asset->useful_life_months || $asset->useful_life_months <= 0) {
            return [false, 'Umur ekonomis belum diisi'];
        }

        $purchaseDate = Carbon::parse($asset->purchase_date);

        // Bulan pembelian tidak dihitung — mulai bulan berikutnya
        $firstDepreciationMonth = $purchaseDate->copy()->addMonthNoOverflow()->startOfMonth();
        $targetMonth = $targetDate->copy()->startOfMonth();

        if ($targetMonth->lt($firstDepreciationMonth)) {
            return [false, "Belum eligible: pembelian {$purchaseDate->format('Y-m')}, depresiasi mulai {$firstDepreciationMonth->format('Y-m')}"];
        }

        // Hitung berapa bulan sudah lewat sejak first depreciation
        $monthsElapsed = $firstDepreciationMonth->diffInMonths($targetMonth) + 1;
        if ($monthsElapsed > (int) $asset->useful_life_months) {
            return [false, "Fully depreciated (umur ekonomis {$asset->useful_life_months} bulan sudah habis)"];
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
