<?php

namespace App\Services\Accounting;

use App\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrialBalanceService
{
    /**
     * Ambil saldo neraca saldo per akun untuk periode tertentu.
     *
     * Logic:
     *  - Sum debit & kredit dari journal_entry_lines (status posted)
     *  - Filter sampai periode (year + optional month — cumulative)
     *  - Hitung saldo: jika debit > kredit → saldo_debit, sebaliknya → saldo_kredit
     *  - Bisa filter per business_unit_id (untuk L/R per lini)
     *
     * @return Collection<int, object{
     *     account_id: int,
     *     code: string,
     *     name: string,
     *     category: string,
     *     sub_category: string|null,
     *     normal_balance: string,
     *     cash_flow_category: string|null,
     *     total_debit: float,
     *     total_kredit: float,
     *     saldo_debit: float,
     *     saldo_kredit: float,
     *     saldo: float
     * }>
     */
    public function getBalances(
        int $companyId,
        int $year,
        ?int $month = null,
        ?int $businessUnitId = null,
        bool $includeZero = false,
        string $scopeMode = 'cumulative',
    ): Collection {
        // BUG-16: parameter $scopeMode kontrol filter periode.
        //   'cumulative' (default) — semua transaksi <= $year (untuk Neraca/BS,
        //                            karena saldo aset/kewajiban/ekuitas akumulatif).
        //   'period'                — hanya transaksi di $year saja (untuk L/R,
        //                            supaya revenue tahun sebelumnya tidak dobel-counted).
        // Subquery: aggregate per account_id dari journal lines yang memenuhi filter
        $agg = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->when($scopeMode === 'cumulative', function ($q) use ($year, $month) {
                // Neraca / TB: kumulatif sejak inception s/d $year (dan optionally $month)
                $q->where('je.period_year', '<=', $year)
                    ->when($month !== null, function ($q2) use ($year, $month) {
                        $q2->where(function ($q3) use ($year, $month) {
                            $q3->where('je.period_year', '<', $year)
                               ->orWhere(function ($q4) use ($year, $month) {
                                   $q4->where('je.period_year', $year)
                                      ->where('je.period_month', '<=', $month);
                               });
                        });
                    });
            }, function ($q) use ($year, $month) {
                // L/R (period mode): HANYA tahun berjalan. Kalau month diisi,
                // batasi lebih ketat ke bulan Jan s/d $month tahun $year.
                $q->where('je.period_year', $year)
                    ->when($month !== null, fn ($q2) => $q2->where('je.period_month', '<=', $month));
            })
            ->when($businessUnitId !== null, fn ($q) => $q->where('je.business_unit_id', $businessUnitId))
            ->groupBy('jl.account_id')
            ->select(
                'jl.account_id',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.kredit) as total_kredit'),
            );

        $rows = collect(
            DB::table('accounts')
                ->leftJoinSub($agg, 'agg', 'agg.account_id', '=', 'accounts.id')
                ->where('accounts.company_id', $companyId)
                ->select([
                    'accounts.id as account_id',
                    'accounts.code',
                    'accounts.name',
                    'accounts.category',
                    'accounts.sub_category',
                    'accounts.normal_balance',
                    'accounts.cash_flow_category',
                    DB::raw('COALESCE(agg.total_debit, 0) as total_debit'),
                    DB::raw('COALESCE(agg.total_kredit, 0) as total_kredit'),
                ])
                ->orderBy('accounts.code')
                ->get()
        );

        $balances = $rows->map(function ($row) {
            $totalDebit  = (float) $row->total_debit;
            $totalKredit = (float) $row->total_kredit;
            $diff        = round($totalDebit - $totalKredit, 2);

            $row->total_debit  = $totalDebit;
            $row->total_kredit = $totalKredit;
            $row->saldo_debit  = $diff > 0 ? $diff : 0.0;
            $row->saldo_kredit = $diff < 0 ? abs($diff) : 0.0;
            $row->saldo        = $row->normal_balance === 'debit' ? $diff : -$diff;

            return $row;
        });

        if (! $includeZero) {
            $balances = $balances->filter(fn ($r) => $r->total_debit > 0 || $r->total_kredit > 0);
        }

        return $balances->values();
    }

    /**
     * Group neraca saldo per kategori untuk tampilan.
     *
     * @return array<string, Collection>
     */
    public function getBalancesByCategory(int $companyId, int $year, ?int $month = null): array
    {
        $balances = $this->getBalances($companyId, $year, $month);

        return [
            'aset'       => $balances->where('category', 'aset')->values(),
            'kewajiban'  => $balances->where('category', 'kewajiban')->values(),
            'ekuitas'    => $balances->where('category', 'ekuitas')->values(),
            'pendapatan' => $balances->where('category', 'pendapatan')->values(),
            'beban'      => $balances->where('category', 'beban')->values(),
            'penutup'    => $balances->where('category', 'penutup')->values(),
        ];
    }

    /**
     * Hitung grand total debit & kredit (untuk validasi balance).
     *
     * @return array{total_debit: float, total_kredit: float, is_balanced: bool}
     */
    public function getGrandTotal(int $companyId, int $year, ?int $month = null): array
    {
        $balances = $this->getBalances($companyId, $year, $month);

        $totalDebit  = (float) $balances->sum('saldo_debit');
        $totalKredit = (float) $balances->sum('saldo_kredit');

        return [
            'total_debit'  => $totalDebit,
            'total_kredit' => $totalKredit,
            'is_balanced'  => round($totalDebit, 2) === round($totalKredit, 2),
        ];
    }

    /**
     * Saldo satu akun (dipakai service lain seperti CashFlow & EquityStatement).
     *
     * DESCENDANT-AWARE (sejak F11.11):
     * Kalau akun dengan code tersebut adalah HEADER (punya sub-akun),
     * saldo dihitung sebagai SUM dari semua descendant.
     *
     * Contoh: 331100 Modal Pemilik di-split jadi 331100-01 dan 331100-02,
     * getAccountBalance('331100') akan return total saldo dari kedua child.
     */
    public function getAccountBalance(int $companyId, string $accountCode, int $year, ?int $month = null): float
    {
        // Ambil semua descendant IDs (termasuk akun itu sendiri kalau leaf)
        $accountIds = Account::descendantIds($accountCode, $companyId, includeSelf: true);

        if (empty($accountIds)) {
            return 0.0;
        }

        $balances = $this->getBalances($companyId, $year, $month, includeZero: true);

        // Aggregate saldo dari semua akun (parent + children)
        return (float) $balances
            ->whereIn('account_id', $accountIds)
            ->sum('saldo');
    }
}
