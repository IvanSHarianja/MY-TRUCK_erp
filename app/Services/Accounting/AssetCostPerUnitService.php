<?php

namespace App\Services\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BIZ-05 — Laporan Biaya Operasional per Unit.
 *
 * Tujuan bisnis (dari notulen Pak Iqbal, poin 4):
 *   Owner bisa lihat "excavator X biayanya Rp 250rb/jam, tapi tarif rentalnya
 *   Rp 200rb/jam" → jelas unit ini rugi struktural, harus dinaikkan tarif atau
 *   di-divestasi. Insight yang tidak bisa didapat dari Laba Rugi umum.
 *
 * Sumber data:
 *   - Cost & Revenue: aggregasi `journal_entry_lines.asset_id` (BBM, gaji,
 *     maintenance, penyusutan straight+usage) — SAMA basis dengan
 *     IncomeStatementByAssetService. Pembalik void otomatis net-off.
 *   - Usage denominator: langsung dari `rental_logs.jam_kerja` dan
 *     `rit_logs.rit_count` di periode. Bukan dari jurnal, karena jurnal
 *     tidak simpan unit fisik.
 *
 * Denominator (unit) per aset:
 *   Priority 1: depreciation_method (per_hour → jam, per_rit → rit, per_day → hari).
 *   Priority 2: asset.type (dump_truck → rit, excavator/bulldozer/wheel_loader → jam).
 *   Fallback: null (aset non-produksi, tidak punya per-unit metric).
 */
class AssetCostPerUnitService
{
    /**
     * @param  array{type?: ?string, business_unit_id?: ?int, only_losing?: bool}  $filters
     */
    public function getReport(int $companyId, int $year, int $month, array $filters = []): array
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();

        // 1. Aset (dengan filter)
        $assetsQuery = Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('asset_code');

        if (! empty($filters['type'])) {
            $assetsQuery->where('type', $filters['type']);
        }
        if (! empty($filters['business_unit_id'])) {
            $assetsQuery->where('default_business_unit_id', $filters['business_unit_id']);
        }

        $assets = $assetsQuery->get();

        // 2. Cost + Revenue per asset (dari journal lines tagged asset_id).
        //    Filter pembalik: sudah otomatis net-off via SUM(kredit-debit) / (debit-kredit).
        //
        // HIGH-2: Filter pakai period_year + period_month (bukan entry_date range)
        // supaya konsisten dengan IncomeStatement*Service. Jurnal adjustment yang
        // di-post di bulan berikutnya tapi period_month-nya bulan sebelumnya (mis.
        // koreksi akuntansi tgl 5 Sep untuk period Agustus) tetap counted di bulan
        // yang benar. Sebelumnya laporan ini miss adjustment tsb.
        $ledgerAgg = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.period_year', $year)
            ->where('je.period_month', $month)
            ->whereNotNull('jl.asset_id')
            ->selectRaw("
                jl.asset_id,
                SUM(CASE WHEN a.category='pendapatan' THEN (jl.kredit - jl.debit) ELSE 0 END) as revenue,
                SUM(CASE WHEN a.category='beban' THEN (jl.debit - jl.kredit) ELSE 0 END) as cost_total,
                SUM(CASE WHEN a.code IN ('551100') THEN (jl.debit - jl.kredit) ELSE 0 END) as cost_bbm,
                SUM(CASE WHEN a.code IN ('552200') THEN (jl.debit - jl.kredit) ELSE 0 END) as cost_gaji,
                SUM(CASE WHEN a.code IN ('551200') THEN (jl.debit - jl.kredit) ELSE 0 END) as cost_premi,
                SUM(CASE WHEN a.code IN ('552100') THEN (jl.debit - jl.kredit) ELSE 0 END) as cost_penyusutan,
                SUM(CASE WHEN a.code IN ('552300') THEN (jl.debit - jl.kredit) ELSE 0 END) as cost_maintenance
            ")
            ->groupBy('jl.asset_id')
            ->get()
            ->keyBy('asset_id');

        // 3. Usage per aset — jam (RentalLog) & rit (RitLog).
        $rentalUsage = DB::table('rental_logs')
            ->where('company_id', $companyId)
            ->whereBetween('log_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereNotNull('asset_id')
            ->selectRaw('asset_id, SUM(jam_kerja) as total_jam')
            ->groupBy('asset_id')
            ->pluck('total_jam', 'asset_id');

        $ritUsage = DB::table('rit_logs')
            ->where('company_id', $companyId)
            ->whereBetween('log_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereNotNull('asset_id')
            ->selectRaw('asset_id, SUM(rit_count) as total_rit')
            ->groupBy('asset_id')
            ->pluck('total_rit', 'asset_id');

        // 4. Build rows
        $rows = [];
        $totals = [
            'revenue' => 0.0, 'cost' => 0.0, 'net' => 0.0,
            'jam' => 0.0, 'rit' => 0.0,
        ];

        foreach ($assets as $asset) {
            $ledger = $ledgerAgg->get($asset->id);
            $revenue = (float) ($ledger->revenue ?? 0);
            $costTotal = (float) ($ledger->cost_total ?? 0);
            $costBbm = (float) ($ledger->cost_bbm ?? 0);
            $costGaji = (float) ($ledger->cost_gaji ?? 0);
            $costPremi = (float) ($ledger->cost_premi ?? 0);
            $costPenyusutan = (float) ($ledger->cost_penyusutan ?? 0);
            $costMaint = (float) ($ledger->cost_maintenance ?? 0);
            $net = $revenue - $costTotal;

            $totalJam = (float) ($rentalUsage[$asset->id] ?? 0);
            $totalRit = (float) ($ritUsage[$asset->id] ?? 0);

            $channel = $this->detectUsageChannel($asset, $totalJam, $totalRit);
            $primaryUsage = match ($channel) {
                'jam'   => $totalJam,
                'rit'   => $totalRit,
                default => 0.0,
            };

            $costPerUnit    = ($primaryUsage > 0) ? round($costTotal / $primaryUsage, 2) : null;
            $revenuePerUnit = ($primaryUsage > 0) ? round($revenue   / $primaryUsage, 2) : null;
            $marginPerUnit  = ($costPerUnit !== null && $revenuePerUnit !== null)
                ? round($revenuePerUnit - $costPerUnit, 2)
                : null;
            $marginPct      = ($revenue > 0) ? round(($revenue - $costTotal) / $revenue * 100, 1) : null;

            $isLosing = $marginPerUnit !== null && $marginPerUnit < 0;

            $rows[] = [
                'asset_id'          => $asset->id,
                'asset_code'        => $asset->asset_code,
                'name'              => $asset->name,
                'type'              => $asset->type,
                'method'            => $asset->depreciation_method?->value,
                'channel'           => $channel, // 'jam' | 'rit' | 'hari' | null
                'usage'             => $primaryUsage,
                'total_jam'         => $totalJam,
                'total_rit'         => $totalRit,
                'revenue'           => $revenue,
                'cost_total'        => $costTotal,
                'cost_breakdown'    => [
                    'bbm'         => $costBbm,
                    'gaji'        => $costGaji,
                    'premi'       => $costPremi,
                    'penyusutan'  => $costPenyusutan,
                    'maintenance' => $costMaint,
                ],
                'net'               => $net,
                'cost_per_unit'     => $costPerUnit,
                'revenue_per_unit'  => $revenuePerUnit,
                'margin_per_unit'   => $marginPerUnit,
                'margin_pct'        => $marginPct,
                'is_losing'         => $isLosing,
                'has_activity'      => ($revenue + $costTotal + $primaryUsage) > 0,
            ];

            $totals['revenue'] += $revenue;
            $totals['cost']    += $costTotal;
            $totals['net']     += $net;
            $totals['jam']     += $totalJam;
            $totals['rit']     += $totalRit;
        }

        // 5. Filter "only losing" — dilakukan setelah semua row terbentuk
        //    supaya totals tetap accurate for full picture.
        if (! empty($filters['only_losing'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['is_losing']));
        }

        // 6. Sort: rugi paling parah dulu (margin_per_unit ascending, null di bawah)
        usort($rows, function ($a, $b) {
            $marginA = $a['margin_per_unit'];
            $marginB = $b['margin_per_unit'];
            if ($marginA === null && $marginB === null) return strcmp($a['asset_code'], $b['asset_code']);
            if ($marginA === null) return 1;
            if ($marginB === null) return -1;
            return $marginA <=> $marginB;
        });

        return [
            'period_label' => $this->periodLabel($year, $month),
            'period_start' => $periodStart->toDateString(),
            'period_end'   => $periodEnd->toDateString(),
            'rows'         => $rows,
            'totals'       => $totals,
            'losing_count' => count(array_filter($rows, fn ($r) => $r['is_losing'])),
        ];
    }

    /**
     * Tentukan channel usage primer untuk aset ini.
     * Priority:
     *   1. depreciation_method (paling eksplisit)
     *   2. Kalau ada usage aktual (jam / rit) → pilih yang > 0
     *   3. Fallback ke asset.type
     */
    private function detectUsageChannel(Asset $asset, float $totalJam, float $totalRit): ?string
    {
        $method = $asset->depreciation_method;

        if ($method instanceof DepreciationMethod) {
            return match ($method) {
                DepreciationMethod::PerHour => 'jam',
                DepreciationMethod::PerRit  => 'rit',
                DepreciationMethod::PerDay  => 'hari',
                default                     => null, // straight_line → fallthrough
            } ?? $this->channelFromType($asset->type, $totalJam, $totalRit);
        }

        return $this->channelFromType($asset->type, $totalJam, $totalRit);
    }

    private function channelFromType(?string $type, float $totalJam, float $totalRit): ?string
    {
        // Kalau ada aktivitas aktual, prioritaskan berdasar itu (paling akurat)
        if ($totalJam > 0 && $totalRit == 0) return 'jam';
        if ($totalRit > 0 && $totalJam == 0) return 'rit';

        // Kalau dua-duanya 0 atau dua-duanya ada → tebakan berbasis type
        return match ($type) {
            'dump_truck'                             => 'rit',
            'excavator', 'bulldozer', 'wheel_loader' => 'jam',
            default                                  => null,
        };
    }

    public static function channelLabel(?string $channel): string
    {
        return match ($channel) {
            'jam'   => 'jam',
            'rit'   => 'rit',
            'hari'  => 'hari',
            default => '-',
        };
    }

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
        return $months[$month - 1] . ' ' . $year;
    }
}
