<?php

namespace App\Services\Accounting;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Laba Rugi per Unit (per Aset).
 *
 * Basis data: kolom `journal_entry_lines.asset_id` (tagged oleh service
 * MaintenanceService, DepreciationService, RentalLog/RitLog Observer, dan
 * InvoiceService untuk revenue rental/armada).
 *
 * Aset tanpa transaksi apa pun tetap ditampilkan (dengan angka 0) — supaya
 * user aware ada aset yang belum menghasilkan revenue.
 *
 * Filter pembalik: dokumen 'pembalik' di-exclude (efek void = 0 di laporan).
 */
class IncomeStatementByAssetService
{
    /**
     * @return array{
     *     assets: array<int, array<string, mixed>>,
     *     totals: array<string, float>,
     * }
     */
    public function getReport(int $companyId, int $year, ?int $month = null): array
    {
        // Ambil semua aset (aktif + maintenance + non_aktif) supaya user
        // bisa lihat aset yang di-retire tapi masih ada residu jurnal.
        $assets = Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('asset_code')
            ->get();

        // Agregat per aset dari journal_entry_lines yang tag asset_id.
        $agg = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->where('je.period_year', '<=', $year)
            ->when($month !== null, function ($q) use ($year, $month) {
                $q->where(function ($q2) use ($year, $month) {
                    $q2->where('je.period_year', '<', $year)
                        ->orWhere(function ($q3) use ($year, $month) {
                            $q3->where('je.period_year', $year)
                                ->where('je.period_month', '<=', $month);
                        });
                });
            })
            ->whereNotNull('jl.asset_id')
            ->selectRaw("
                jl.asset_id,
                SUM(CASE WHEN a.category='pendapatan' THEN (jl.kredit - jl.debit) ELSE 0 END) as revenue,
                SUM(CASE WHEN a.category='beban' AND a.sub_category='beban_hpp' THEN (jl.debit - jl.kredit) ELSE 0 END) as hpp,
                SUM(CASE WHEN a.category='beban' AND a.sub_category='beban_operasional' THEN (jl.debit - jl.kredit) ELSE 0 END) as beban_op
            ")
            ->groupBy('jl.asset_id')
            ->get()
            ->keyBy('asset_id');

        $rows = [];
        $totalRevenue = 0.0;
        $totalHpp = 0.0;
        $totalBebanOp = 0.0;

        foreach ($assets as $asset) {
            $data = $agg->get($asset->id);
            $revenue  = (float) ($data->revenue  ?? 0);
            $hpp      = (float) ($data->hpp      ?? 0);
            $bebanOp  = (float) ($data->beban_op ?? 0);
            $labaKotor  = $revenue - $hpp;
            $labaBersih = $labaKotor - $bebanOp;
            $margin     = $revenue > 0 ? round($labaBersih / $revenue * 100, 1) : null;

            $rows[] = [
                'asset_id'    => $asset->id,
                'asset_code'  => $asset->asset_code,
                'name'        => $asset->name,
                'type'        => $asset->type,
                'status'      => $asset->status,
                'revenue'     => $revenue,
                'hpp'         => $hpp,
                'beban_op'    => $bebanOp,
                'laba_kotor'  => $labaKotor,
                'laba_bersih' => $labaBersih,
                'margin'      => $margin,
                'has_activity'=> ($revenue + $hpp + $bebanOp) > 0,
            ];

            $totalRevenue  += $revenue;
            $totalHpp      += $hpp;
            $totalBebanOp  += $bebanOp;
        }

        $totalLabaKotor = $totalRevenue - $totalHpp;
        $totalLabaBersih = $totalLabaKotor - $totalBebanOp;
        $totalMargin = $totalRevenue > 0 ? round($totalLabaBersih / $totalRevenue * 100, 1) : null;

        return [
            'assets' => $rows,
            'totals' => [
                'revenue'     => $totalRevenue,
                'hpp'         => $totalHpp,
                'beban_op'    => $totalBebanOp,
                'laba_kotor'  => $totalLabaKotor,
                'laba_bersih' => $totalLabaBersih,
                'margin'      => $totalMargin,
            ],
        ];
    }

    /**
     * Human-readable label untuk asset type.
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
}
