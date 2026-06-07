<?php

namespace App\Services\Accounting;

use Illuminate\Support\Collection;

class IncomeStatementService
{
    public function __construct(private TrialBalanceService $trialBalance) {}

    /**
     * Laporan Laba Rugi:
     *  I.  PENDAPATAN USAHA (4xxx)
     *  II. BEBAN POKOK PENDAPATAN / HPP (51xx)
     *  III. LABA KOTOR = Pendapatan - HPP
     *  IV. BEBAN OPERASIONAL (52xx, 53xx)
     *  V.  LABA BERSIH SEBELUM PAJAK = Laba Kotor - Beban Operasional
     *
     * @return array{
     *   pendapatan: Collection, totalPendapatan: float,
     *   hpp: Collection, totalHpp: float,
     *   labaKotor: float,
     *   bebanOp: Collection, totalBebanOp: float,
     *   labaBersih: float,
     *   marginLabaBersih: float
     * }
     */
    public function getReport(
        int $companyId,
        int $year,
        ?int $month = null,
        ?int $businessUnitId = null,
    ): array {
        $balances = $this->trialBalance->getBalances($companyId, $year, $month, $businessUnitId);

        // Pakai category + sub_category dari accounts (lebih reliable daripada parse kode)
        $pendapatan = $balances->filter(fn ($r) => $r->category === 'pendapatan')->values();
        $hpp        = $balances->filter(fn ($r) => $r->category === 'beban' && $r->sub_category === 'beban_hpp')->values();
        $bebanOp    = $balances->filter(fn ($r) => $r->category === 'beban' && $r->sub_category === 'beban_operasional')->values();

        $totalPendapatan = (float) $pendapatan->sum('saldo_kredit');
        $totalHpp        = (float) $hpp->sum('saldo_debit');
        $labaKotor       = $totalPendapatan - $totalHpp;
        $totalBebanOp    = (float) $bebanOp->sum('saldo_debit');
        $labaBersih      = $labaKotor - $totalBebanOp;
        $marginLaba      = $totalPendapatan > 0 ? round($labaBersih / $totalPendapatan * 100, 2) : 0.0;

        return compact(
            'pendapatan', 'totalPendapatan',
            'hpp', 'totalHpp',
            'labaKotor',
            'bebanOp', 'totalBebanOp',
            'labaBersih', 'marginLaba',
        );
    }

    /** Shortcut untuk dashboard / Neraca. */
    public function getNetProfit(int $companyId, int $year, ?int $month = null, ?int $businessUnitId = null): float
    {
        return $this->getReport($companyId, $year, $month, $businessUnitId)['labaBersih'];
    }

    /** Shortcut total pendapatan. */
    public function getTotalRevenue(int $companyId, int $year, ?int $month = null, ?int $businessUnitId = null): float
    {
        return $this->getReport($companyId, $year, $month, $businessUnitId)['totalPendapatan'];
    }
}
