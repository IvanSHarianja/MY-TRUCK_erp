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
        // Sprint 2.5: role-based lookup. Kalau user pakai kode custom untuk
        // Modal/Prive/Laba Ditahan, tetap muncul di laporan asal role di-set benar.
        $modalCode        = $this->resolveCodeByRole($companyId, \App\Enums\AccountRole::EquityModal, '331100');
        $priveCode        = $this->resolveCodeByRole($companyId, \App\Enums\AccountRole::EquityPrive, '331200');
        $labaDitahanCode  = $this->resolveCodeByRole($companyId, \App\Enums\AccountRole::EquityLabaDitahan, '331300');

        $modalPemilik  = $this->trialBalance->getAccountBalance($companyId, $modalCode, $year, $month);
        $labaDitahan   = $this->trialBalance->getAccountBalance($companyId, $labaDitahanCode, $year, $month);
        $priveRaw      = $this->trialBalance->getAccountBalance($companyId, $priveCode, $year, $month);
        $labaBerjalan  = $this->incomeStatement->getNetProfit($companyId, $year, $month);

        // Saldo akun 331200 (Prive) normal_balance = debit, jadi saldo positif berarti pengambilan.
        // getAccountBalance return: normal_balance debit → diff >0 berarti saldo. Sudah positif.
        $prive = abs($priveRaw);

        $totalEkuitas = $modalPemilik + $labaDitahan + $labaBerjalan - $prive;

        return compact(
            'modalPemilik', 'labaDitahan', 'labaBerjalan', 'prive', 'totalEkuitas',
        );
    }

    /**
     * Resolve code akun berdasar role, dengan fallback ke code standar.
     * Kalau tenant pakai code custom (e.g. 3000000-01), role akan mengarah ke
     * akun itu; kalau role belum di-set, pakai code standar (backward compat).
     */
    private function resolveCodeByRole(int $companyId, \App\Enums\AccountRole $role, string $fallbackCode): string
    {
        $account = \App\Models\Account::firstByRole($role, $companyId);
        return $account?->code ?? $fallbackCode;
    }
}
