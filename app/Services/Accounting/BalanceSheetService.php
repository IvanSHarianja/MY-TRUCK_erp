<?php

namespace App\Services\Accounting;

use Illuminate\Support\Collection;

class BalanceSheetService
{
    public function __construct(
        private TrialBalanceService $trialBalance,
        private IncomeStatementService $incomeStatement,
    ) {}

    /**
     * Laporan Neraca / Balance Sheet:
     *  ASET = 11xx (Lancar) + 12xx (Tetap, net setelah akumulasi penyusutan)
     *  KEWAJIBAN = 21xx (Lancar) + 22xx (Panjang)
     *  EKUITAS = 31xx + Laba Berjalan (dari L/R)
     *
     * @return array{
     *   asetLancar: Collection, totalAsetLancar: float,
     *   asetTetap: Collection, totalAsetTetap: float,
     *   totalAset: float,
     *   kwjbLancar: Collection, totalKwjbLancar: float,
     *   kwjbPanjang: Collection, totalKwjbPanjang: float,
     *   totalKewajiban: float,
     *   ekuitas: Collection, totalEkuitasBase: float,
     *   labaBerjalan: float,
     *   totalEkuitas: float,
     *   totalPasiva: float,
     *   isBalanced: bool,
     *   selisih: float
     * }
     */
    public function getReport(int $companyId, int $year, ?int $month = null): array
    {
        $balances = $this->trialBalance->getBalances($companyId, $year, $month);

        // === ASET — pakai sub_category ===
        $asetLancar = $balances->filter(fn ($r) => $r->category === 'aset' && $r->sub_category === 'aset_lancar')->values();
        $asetTetap  = $balances->filter(fn ($r) => $r->category === 'aset' && $r->sub_category === 'aset_tetap')->values();

        // Aset tetap: untuk akun akumulasi penyusutan (normal_balance kredit), nilai dikurangi
        $sumAsetTetap = $asetTetap->reduce(function ($carry, $row) {
            if ($row->normal_balance === 'kredit') {
                return $carry - (float) $row->saldo_kredit;  // akumulasi penyusutan kurangi aset
            }
            return $carry + (float) $row->saldo_debit;
        }, 0.0);

        $totalAsetLancar = (float) $asetLancar->sum('saldo_debit');
        $totalAsetTetap  = (float) $sumAsetTetap;
        $totalAset       = $totalAsetLancar + $totalAsetTetap;

        // === KEWAJIBAN ===
        $kwjbLancar  = $balances->filter(fn ($r) => $r->category === 'kewajiban' && $r->sub_category === 'kewajiban_lancar')->values();
        $kwjbPanjang = $balances->filter(fn ($r) => $r->category === 'kewajiban' && $r->sub_category === 'kewajiban_panjang')->values();

        $totalKwjbLancar  = (float) $kwjbLancar->sum('saldo_kredit');
        $totalKwjbPanjang = (float) $kwjbPanjang->sum('saldo_kredit');
        $totalKewajiban   = $totalKwjbLancar + $totalKwjbPanjang;

        // === EKUITAS ===
        // Ekuitas dasar: akun kategori ekuitas (kecuali laba berjalan yang dihitung dari L/R)
        $ekuitas = $balances->filter(fn ($r) => $r->category === 'ekuitas' && $r->code !== '331400')->values();

        $totalEkuitasBase = $ekuitas->reduce(function ($carry, $row) {
            if ($row->normal_balance === 'kredit') {
                return $carry + (float) $row->saldo_kredit;
            }
            return $carry - (float) $row->saldo_debit;  // prive kurangi ekuitas
        }, 0.0);

        // Laba berjalan dari L/R
        $labaBerjalan  = $this->incomeStatement->getNetProfit($companyId, $year, $month);
        $totalEkuitas  = $totalEkuitasBase + $labaBerjalan;

        $totalPasiva = $totalKewajiban + $totalEkuitas;
        $selisih     = round($totalAset - $totalPasiva, 2);
        $isBalanced  = $selisih === 0.0;

        return compact(
            'asetLancar', 'totalAsetLancar',
            'asetTetap',  'totalAsetTetap',
            'totalAset',
            'kwjbLancar', 'totalKwjbLancar',
            'kwjbPanjang','totalKwjbPanjang',
            'totalKewajiban',
            'ekuitas',    'totalEkuitasBase',
            'labaBerjalan',
            'totalEkuitas',
            'totalPasiva',
            'isBalanced', 'selisih',
        );
    }

    public function getTotalAset(int $companyId, int $year, ?int $month = null): float
    {
        return $this->getReport($companyId, $year, $month)['totalAset'];
    }

    public function getTotalKewajiban(int $companyId, int $year, ?int $month = null): float
    {
        return $this->getReport($companyId, $year, $month)['totalKewajiban'];
    }

    public function getTotalEkuitas(int $companyId, int $year, ?int $month = null): float
    {
        return $this->getReport($companyId, $year, $month)['totalEkuitas'];
    }
}
