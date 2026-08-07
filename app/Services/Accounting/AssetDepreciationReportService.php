<?php

namespace App\Services\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BIZ-04 — Laporan Penyusutan per Aset.
 *
 * Sumber data (single source of truth):
 *   Ledger `journal_entry_lines` yang menyentuh akun Akumulasi Penyusutan
 *   (category=aset, sub_category=aset_tetap, normal_balance=kredit), difilter
 *   per `asset_id` dan `entry_date <= as_of`.
 *
 * Kenapa dari ledger, bukan kalkulasi ideal Asset->monthly_depreciation × N?
 *   - Ledger merefleksikan kondisi AKTUAL: void, adjustment manual, skip periode.
 *   - Konsisten dengan Neraca (BalanceSheet): angka akumulasi laporan ini
 *     harus MATCH dengan section Aset Tetap di Neraca per tanggal sama.
 *   - Pembalik (void) otomatis di-net-off via SUM(kredit - debit).
 *
 * Cakupan aset:
 *   Semua aset (aktif/maintenance/non_aktif). Non_aktif tetap tampil supaya
 *   user aware ada aset di-retire yang masih punya residu buku.
 */
class AssetDepreciationReportService
{
    /**
     * @param  array{type?: ?string, business_unit_id?: ?int, method?: ?string}  $filters
     * @return array{
     *   period_label: string,
     *   as_of: string,
     *   rows: array<int, array<string, mixed>>,
     *   totals: array{purchase_price: float, akumulasi: float, nilai_buku: float, next_month_dep: float},
     * }
     */
    public function getReport(int $companyId, int $year, int $month, array $filters = []): array
    {
        $asOf = Carbon::create($year, $month, 1)->endOfMonth();

        // 1. Ambil aset (dengan filter)
        $assetQuery = Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('type')
            ->orderBy('asset_code');

        if (! empty($filters['type'])) {
            $assetQuery->where('type', $filters['type']);
        }
        if (! empty($filters['business_unit_id'])) {
            $assetQuery->where('default_business_unit_id', $filters['business_unit_id']);
        }
        if (! empty($filters['method'])) {
            $assetQuery->where('depreciation_method', $filters['method']);
        }

        $assets = $assetQuery->get();

        // 2. Agregat akumulasi per asset_id dari ledger s/d as_of
        //    (source of truth — konsisten dengan Neraca).
        $agg = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $asOf->toDateString())
            ->where('a.category', 'aset')
            ->where('a.sub_category', 'aset_tetap')
            ->where('a.normal_balance', 'kredit') // = akumulasi (kontra-aset)
            ->whereNotNull('jl.asset_id')
            ->selectRaw('jl.asset_id, SUM(jl.kredit - jl.debit) as akumulasi')
            ->groupBy('jl.asset_id')
            ->get()
            ->keyBy('asset_id');

        // 3. Build rows + hitung sisa umur & estimasi bulan depan
        $rows = [];
        $totalPrice = 0.0;
        $totalAkum = 0.0;
        $totalNextDep = 0.0;

        foreach ($assets as $asset) {
            $method       = $asset->depreciation_method;
            $price        = (float) $asset->purchase_price;
            $salvage      = (float) $asset->salvage_value;
            $akumulasi    = (float) ($agg->get($asset->id)->akumulasi ?? 0);
            $nilaiBuku    = round($price - $akumulasi, 2);
            $depreciableBase = max(0, $price - $salvage);

            [$sisaUmur, $sisaUmurUnit, $nextMonthDep] = $this->computeSisaUmurAndNext(
                $asset, $akumulasi, $asOf, $depreciableBase,
            );

            $rows[] = [
                'asset_id'         => $asset->id,
                'asset_code'       => $asset->asset_code,
                'name'             => $asset->name,
                'type'             => $asset->type,
                'method'           => $method?->value,
                'method_label'     => $method?->label() ?? '-',
                'purchase_date'    => $asset->purchase_date?->toDateString(),
                'purchase_price'   => $price,
                'salvage_value'    => $salvage,
                'akumulasi'        => $akumulasi,
                'nilai_buku'       => $nilaiBuku,
                'sisa_umur'        => $sisaUmur,
                'sisa_umur_unit'   => $sisaUmurUnit,
                'next_month_dep'   => $nextMonthDep,
                'status'           => $asset->status,
                'fully_depreciated' => $nilaiBuku <= ($salvage + 0.005),
            ];

            $totalPrice   += $price;
            $totalAkum    += $akumulasi;
            $totalNextDep += $nextMonthDep;
        }

        return [
            'period_label' => $this->periodLabel($year, $month),
            'as_of'        => $asOf->toDateString(),
            'rows'         => $rows,
            'totals'       => [
                'purchase_price' => $totalPrice,
                'akumulasi'      => $totalAkum,
                'nilai_buku'     => round($totalPrice - $totalAkum, 2),
                'next_month_dep' => $totalNextDep,
            ],
        ];
    }

    /**
     * Hitung sisa umur (dalam satuan method) + estimasi dep bulan berikut.
     *
     * Straight line:
     *   - Sisa umur bulan = useful_life_months - months_elapsed
     *   - Next month dep = monthly, atau 0 kalau sudah fully depreciated
     *
     * Usage-based:
     *   - Total unit usage terakumulasi = akumulasi / per_unit_cost
     *     (dibalik dari amount ke unit — hanya perkiraan; angka aktual
     *     tersedia setelah BIZ-03 di journal line description).
     *   - Sisa umur = useful_life_<unit> - total_usage
     *   - Next month dep = 0 (usage-based tidak "tahu" berapa bulan depan
     *     karena tergantung pemakaian aktual). Owner harus baca dari
     *     Laporan Biaya per Unit (BIZ-05).
     *
     * @return array{0: float, 1: string, 2: float}  [sisa_umur, unit_label, next_month_dep]
     */
    private function computeSisaUmurAndNext(
        Asset $asset,
        float $akumulasi,
        Carbon $asOf,
        float $depreciableBase,
    ): array {
        $method = $asset->depreciation_method;

        // Belum ada method (edge case data lama) atau nilai residu ≥ harga beli
        if (! $method instanceof DepreciationMethod || $depreciableBase <= 0) {
            return [0.0, '-', 0.0];
        }

        if ($method === DepreciationMethod::StraightLine) {
            $usefulMonths = (int) ($asset->useful_life_months ?? 0);
            if ($usefulMonths <= 0 || ! $asset->purchase_date) {
                return [0.0, 'bulan', 0.0];
            }

            $purchase = Carbon::parse($asset->purchase_date);
            $firstDepMonth = $purchase->copy()->addMonthNoOverflow()->startOfMonth();
            $target = $asOf->copy()->startOfMonth();

            $monthsElapsed = $target->gte($firstDepMonth)
                ? ((int) round($firstDepMonth->diffInMonths($target)) + 1)
                : 0;

            $sisa = max(0, $usefulMonths - $monthsElapsed);
            $nextDep = ($sisa > 0) ? (float) $asset->monthly_depreciation : 0.0;

            return [(float) $sisa, 'bulan', $nextDep];
        }

        // Usage-based
        $perUnit = $asset->depreciationPerUnit();
        if ($perUnit <= 0) {
            return [0.0, $method->unitLabel(), 0.0];
        }

        $usageAkum = $akumulasi / $perUnit;
        $usefulTotal = (float) match ($method) {
            DepreciationMethod::PerHour => $asset->useful_life_hours,
            DepreciationMethod::PerRit  => $asset->useful_life_rits,
            DepreciationMethod::PerDay  => $asset->useful_life_days,
            default                     => 0,
        };

        $sisa = max(0.0, round($usefulTotal - $usageAkum, 2));

        return [$sisa, $method->unitLabel(), 0.0];
    }

    /**
     * Label untuk asset type — di-share dengan IncomeStatementByAssetService
     * pattern. Duplikat pendek daripada tarik dependency.
     */
    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'dump_truck'            => 'Dump Truck',
            'excavator'             => 'Excavator',
            'bulldozer'             => 'Bulldozer',
            'wheel_loader'          => 'Wheel Loader',
            'kendaraan_operasional' => 'Kendaraan Op.',
            'peralatan_kantor'      => 'Peralatan Kantor',
            'lainnya'               => 'Lainnya',
            default                 => ucwords(str_replace('_', ' ', (string) $type)),
        };
    }

    private function periodLabel(int $year, int $month): string
    {
        $months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        return "Per akhir " . $months[$month - 1] . " {$year}";
    }
}
