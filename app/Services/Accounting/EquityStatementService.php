<?php

namespace App\Services\Accounting;

class EquityStatementService
{
    public function __construct(
        private TrialBalanceService $trialBalance,
        private IncomeStatementService $incomeStatement,
    ) {}

    /**
     * Laporan Perubahan Ekuitas:
     *   Modal Pemilik (Saldo Awal)         → akun 331100 saldo_kredit
     * + Laba Ditahan (Saldo Awal)          → akun 331300 saldo_kredit
     * + Laba Bersih Tahun Berjalan          → dari L/R
     * - Prive / Pengambilan Modal           → akun 331200 saldo_debit
     * = TOTAL EKUITAS AKHIR
     *
     * @return array{
     *   modalPemilik: float,
     *   labaDitahan: float,
     *   labaBerjalan: float,
     *   prive: float,
     *   totalEkuitas: float
     * }
     */
    public function getReport(int $companyId, int $year, ?int $month = null): array
    {
        $modalPemilik  = $this->trialBalance->getAccountBalance($companyId, '331100', $year, $month);
        $labaDitahan   = $this->trialBalance->getAccountBalance($companyId, '331300', $year, $month);
        $priveRaw      = $this->trialBalance->getAccountBalance($companyId, '331200', $year, $month);
        $labaBerjalan  = $this->incomeStatement->getNetProfit($companyId, $year, $month);

        // Saldo akun 331200 (Prive) normal_balance = debit, jadi saldo positif berarti pengambilan.
        // getAccountBalance return: normal_balance debit → diff >0 berarti saldo. Sudah positif.
        $prive = abs($priveRaw);

        $totalEkuitas = $modalPemilik + $labaDitahan + $labaBerjalan - $prive;

        return compact(
            'modalPemilik', 'labaDitahan', 'labaBerjalan', 'prive', 'totalEkuitas',
        );
    }
}
