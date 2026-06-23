<?php

namespace App\Services\Accounting;

use App\Models\BusinessUnit;
use Illuminate\Support\Facades\DB;

class IncomeStatementMatrixService
{
    /**
     * Build laporan Laba Rugi multi-kolom per Lini Bisnis.
     *
     * Output structure:
     * - businessUnits: koleksi BusinessUnit (kolom)
     * - includesNoLini: bool, apakah ada transaksi tanpa lini (kolom UMUM/Tanpa Lini)
     * - revenuePerLini: [business_unit_id => total_revenue]
     * - hppRows: [['code', 'name', perLini[], total]]
     * - bebanOpRows: same
     * - totalHppPerLini: [business_unit_id => total_hpp]
     * - totalBebanOpPerLini: ...
     * - labaKotorPerLini: ...
     * - labaBersihPerLini: ...
     * - marginPerLini: ...
     */
    public function getReport(int $companyId, int $year, ?int $month = null): array
    {
        $businessUnits = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        // ID untuk kolom "Tanpa Lini" — pakai 0 sebagai sentinel
        $tanpaLiniId = 0;
        $columns = $businessUnits->pluck('id')->push($tanpaLiniId)->all();

        // Aggregate dari journal_entry_lines × accounts × journal_entries (status posted, periode)
        $query = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
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
            ->whereIn('a.category', ['pendapatan', 'beban'])
            ->select(
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.category',
                'a.sub_category',
                'a.normal_balance',
                DB::raw('COALESCE(je.business_unit_id, 0) as bu_id'),
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.kredit) as total_kredit'),
            )
            ->groupBy('a.id', 'a.code', 'a.name', 'a.category', 'a.sub_category', 'a.normal_balance', 'bu_id')
            ->get();

        // Init struktur
        $revenuePerLini = array_fill_keys($columns, 0.0);
        $totalHppPerLini = array_fill_keys($columns, 0.0);
        $totalBebanOpPerLini = array_fill_keys($columns, 0.0);

        // Group per akun pendapatan
        $revenueRows = [];     // ['code', 'name', perLini[id => amt], total]
        $hppRows = [];
        $bebanOpRows = [];

        $byAccount = $query->groupBy('account_id');

        foreach ($byAccount as $accountId => $rows) {
            $first = $rows->first();
            $isPendapatan = $first->category === 'pendapatan';
            $isHpp = $first->category === 'beban' && $first->sub_category === 'beban_hpp';
            $isOp  = $first->category === 'beban' && $first->sub_category === 'beban_operasional';

            $perLini = array_fill_keys($columns, 0.0);
            foreach ($rows as $row) {
                $netCredit = (float) $row->total_kredit - (float) $row->total_debit;
                $netDebit  = (float) $row->total_debit - (float) $row->total_kredit;
                $value = $isPendapatan ? $netCredit : $netDebit;
                $buId  = (int) $row->bu_id;
                $perLini[$buId] = ($perLini[$buId] ?? 0) + $value;
            }

            $totalRow = array_sum($perLini);

            // Skip jika total 0 (tidak relevan)
            if (round($totalRow, 2) === 0.0) continue;

            $rowData = [
                'code'    => $first->code,
                'name'    => $first->name,
                'perLini' => $perLini,
                'total'   => $totalRow,
            ];

            if ($isPendapatan) {
                $revenueRows[] = $rowData;
                foreach ($perLini as $buId => $amt) {
                    $revenuePerLini[$buId] += $amt;
                }
            } elseif ($isHpp) {
                $hppRows[] = $rowData;
                foreach ($perLini as $buId => $amt) {
                    $totalHppPerLini[$buId] += $amt;
                }
            } elseif ($isOp) {
                $bebanOpRows[] = $rowData;
                foreach ($perLini as $buId => $amt) {
                    $totalBebanOpPerLini[$buId] += $amt;
                }
            }
        }

        // Hitung Laba Kotor, Laba Bersih, Margin per lini
        $labaKotorPerLini = [];
        $labaBersihPerLini = [];
        $marginPerLini = [];

        foreach ($columns as $buId) {
            $rev = (float) ($revenuePerLini[$buId] ?? 0);
            $hpp = (float) ($totalHppPerLini[$buId] ?? 0);
            $op  = (float) ($totalBebanOpPerLini[$buId] ?? 0);
            $lk  = $rev - $hpp;
            $lb  = $lk - $op;
            $mrg = $rev > 0 ? round($lb / $rev * 100, 1) : null;
            $labaKotorPerLini[$buId] = $lk;
            $labaBersihPerLini[$buId] = $lb;
            $marginPerLini[$buId] = $mrg;
        }

        // Total semua kolom
        $totalRevenue   = array_sum($revenuePerLini);
        $totalHpp       = array_sum($totalHppPerLini);
        $totalBebanOp   = array_sum($totalBebanOpPerLini);
        $totalLabaKotor = $totalRevenue - $totalHpp;
        $totalLabaBersih = $totalLabaKotor - $totalBebanOp;
        $totalMargin = $totalRevenue > 0 ? round($totalLabaBersih / $totalRevenue * 100, 1) : null;

        // Check apakah ada transaksi "Tanpa Lini" (BU id = 0)
        $hasTanpaLini = ($revenuePerLini[$tanpaLiniId] ?? 0) > 0
            || ($totalHppPerLini[$tanpaLiniId] ?? 0) > 0
            || ($totalBebanOpPerLini[$tanpaLiniId] ?? 0) > 0;

        return [
            'businessUnits' => $businessUnits,
            'tanpaLiniId'   => $tanpaLiniId,
            'hasTanpaLini'  => $hasTanpaLini,
            'columns'       => $columns,

            'revenueRows'         => $revenueRows,
            'hppRows'             => $hppRows,
            'bebanOpRows'         => $bebanOpRows,

            'revenuePerLini'      => $revenuePerLini,
            'totalHppPerLini'     => $totalHppPerLini,
            'totalBebanOpPerLini' => $totalBebanOpPerLini,
            'labaKotorPerLini'    => $labaKotorPerLini,
            'labaBersihPerLini'   => $labaBersihPerLini,
            'marginPerLini'       => $marginPerLini,

            'totalRevenue'    => $totalRevenue,
            'totalHpp'        => $totalHpp,
            'totalBebanOp'    => $totalBebanOp,
            'totalLabaKotor'  => $totalLabaKotor,
            'totalLabaBersih' => $totalLabaBersih,
            'totalMargin'     => $totalMargin,
        ];
    }
}
