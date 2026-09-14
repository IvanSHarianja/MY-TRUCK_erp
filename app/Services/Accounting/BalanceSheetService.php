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
     * Setiap baris di collection punya `nilai` bertanda (negatif = mengurangi
     * seksinya) — view WAJIB menampilkan `nilai`, bukan saldo_debit/saldo_kredit.
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

        // Setiap baris dapat `nilai` BERTANDA sesuai sisi neraca:
        //   Aset             : debit − kredit  (akumulasi penyusutan otomatis negatif)
        //   Kewajiban/Ekuitas: kredit − debit  (prive otomatis negatif)
        //
        // Dulu tampilan & total memakai saldo_debit (aset) / saldo_kredit (pasiva)
        // saja, jadi saldo di sisi berlawanan dibuang: rekening bank yang saldonya
        // kredit karena transfer keluar tampil "–" dan tidak mengurangi total →
        // total aset overstate dan neraca tidak balance. Saldo abnormal harus
        // TERLIHAT (minus), bukan disembunyikan.
        $balances->each(function ($row) {
            $net = round((float) $row->total_debit - (float) $row->total_kredit, 2);
            $row->nilai = $row->category === 'aset' ? $net : -$net;
        });

        // === ASET — pakai sub_category ===
        $asetLancar = $balances->filter(fn ($r) => $r->category === 'aset' && $r->sub_category === 'aset_lancar')->values();
        $asetTetap  = $balances->filter(fn ($r) => $r->category === 'aset' && $r->sub_category === 'aset_tetap')->values();

        $totalAsetLancar = (float) $asetLancar->sum('nilai');
        $totalAsetTetap  = (float) $asetTetap->sum('nilai');
        $totalAset       = $totalAsetLancar + $totalAsetTetap;

        // === KEWAJIBAN ===
        $kwjbLancar  = $balances->filter(fn ($r) => $r->category === 'kewajiban' && $r->sub_category === 'kewajiban_lancar')->values();
        $kwjbPanjang = $balances->filter(fn ($r) => $r->category === 'kewajiban' && $r->sub_category === 'kewajiban_panjang')->values();

        $totalKwjbLancar  = (float) $kwjbLancar->sum('nilai');
        $totalKwjbPanjang = (float) $kwjbPanjang->sum('nilai');
        $totalKewajiban   = $totalKwjbLancar + $totalKwjbPanjang;

        // === EKUITAS ===
        // Ekuitas dasar: akun kategori ekuitas (kecuali laba berjalan yang dihitung dari L/R)
        $ekuitas = $balances->filter(fn ($r) => $r->category === 'ekuitas' && $r->code !== '331400')->values();

        $totalEkuitasBase = (float) $ekuitas->sum('nilai');

        // Laba berjalan dari L/R
        $labaBerjalan  = $this->incomeStatement->getNetProfit($companyId, $year, $month);
        $totalEkuitas  = $totalEkuitasBase + $labaBerjalan;

        $totalPasiva = $totalKewajiban + $totalEkuitas;
        $selisih     = round($totalAset - $totalPasiva, 2);
        // BUG-18: epsilon tolerance
        $isBalanced  = abs($selisih) < 0.005;

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
