<?php

namespace App\Services\Accounting;

use App\Models\Account;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buku Besar (General Ledger): per akun — saldo awal, setiap baris jurnal
 * dalam periode DARI–SAMPAI, dan saldo berjalan.
 *
 * Aturan sama dengan Neraca Saldo (TrialBalanceService) supaya saldo akhir
 * per akun cocok dengan Neraca Saldo:
 *   - hanya jurnal status `posted` (draft & void tidak dihitung)
 *   - jurnal `pembalik` tidak dihitung (efek void = 0)
 *   - saldo dinyatakan sesuai SALDO NORMAL akun (debit: D−K, kredit: K−D)
 * Periode memakai TANGGAL jurnal (entry_date).
 *
 * Total per akun selalu dihitung lewat agregat (murah). Rincian baris hanya
 * dimuat bila jumlahnya ≤ $lineLimit — selebihnya layar menampilkan ringkasan
 * per akun dan user diminta mempersempit filter atau mengunduh Excel.
 */
class GeneralLedgerService
{
    /** Batas baris rincian di layar. Excel/PDF memakai batasnya sendiri. */
    public const SCREEN_LINE_LIMIT = 1500;

    /**
     * @param  int|null  $accountId  null = semua akun yang punya saldo/mutasi;
     *                               akun induk = gabungan semua sub-akunnya.
     * @return array{
     *   accounts: list<array{id: int, code: string, name: string, normal_balance: string,
     *     opening: float, debit: float, kredit: float, closing: float, lines: list<array<string, mixed>>}>,
     *   line_count: int,
     *   totals: array{debit: float, kredit: float},
     *   truncated: bool,
     * }
     */
    public function build(int $companyId, Carbon $dari, Carbon $sampai, ?int $accountId = null, ?int $lineLimit = null): array
    {
        $empty = ['accounts' => [], 'line_count' => 0, 'totals' => ['debit' => 0.0, 'kredit' => 0.0], 'truncated' => false];

        $accounts = $this->accountsInScope($companyId, $accountId);
        if ($accounts->isEmpty()) {
            return $empty;
        }

        $ids   = $accounts->pluck('id')->all();
        $from  = $dari->toDateString();
        $until = $sampai->copy()->addDay()->toDateString();   // batas atas eksklusif (kolom date di SQLite bisa berjam)

        $opening = $this->baseQuery($companyId, $ids)
            ->where('je.entry_date', '<', $from)
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, SUM(jl.debit) - SUM(jl.kredit) AS net')
            ->pluck('net', 'account_id');

        $movement = $this->baseQuery($companyId, $ids)
            ->where('je.entry_date', '>=', $from)
            ->where('je.entry_date', '<', $until)
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, SUM(jl.debit) AS debit, SUM(jl.kredit) AS kredit, COUNT(*) AS n')
            ->get()
            ->keyBy('account_id');

        $lineCount = (int) $movement->sum('n');
        $truncated = $lineLimit !== null && $lineCount > $lineLimit;

        $lines = $truncated ? collect() : $this->baseQuery($companyId, $ids)
            ->where('je.entry_date', '>=', $from)
            ->where('je.entry_date', '<', $until)
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_number')
            ->orderBy('jl.id')
            ->get([
                'jl.account_id', 'jl.debit', 'jl.kredit', 'jl.description as line_description',
                'je.id as entry_id', 'je.entry_date', 'je.entry_number', 'je.document_number',
                'je.description as entry_description',
            ])
            ->groupBy('account_id');

        $result = [];
        $grandDebit = 0.0;
        $grandKredit = 0.0;

        foreach ($accounts as $acc) {
            $sign  = $acc->normal_balance === 'kredit' ? -1 : 1;
            $open  = round($sign * (float) ($opening[$acc->id] ?? 0), 2);
            $mv    = $movement->get($acc->id);
            $debit = round((float) ($mv->debit ?? 0), 2);
            $kred  = round((float) ($mv->kredit ?? 0), 2);

            // "Semua akun": akun tanpa saldo awal & tanpa mutasi tidak ditampilkan.
            if ($accountId === null && $mv === null && abs($open) < 0.005) {
                continue;
            }

            $running = $open;
            $rows = [];
            foreach ($lines->get($acc->id, []) as $l) {
                $d = (float) $l->debit;
                $k = (float) $l->kredit;
                $running = round($running + $sign * ($d - $k), 2);

                $rows[] = [
                    'entry_id'        => (int) $l->entry_id,
                    'date'            => Carbon::parse($l->entry_date)->toDateString(),
                    'entry_number'    => $l->entry_number,
                    'document_number' => $l->document_number,
                    'description'     => $l->line_description ?: $l->entry_description,
                    'debit'           => $d,
                    'kredit'          => $k,
                    'balance'         => $running,
                ];
            }

            $grandDebit  += $debit;
            $grandKredit += $kred;

            $result[] = [
                'id'             => (int) $acc->id,
                'code'           => $acc->code,
                'name'           => $acc->name,
                'normal_balance' => $acc->normal_balance,
                'opening'        => $open,
                'debit'          => $debit,
                'kredit'         => $kred,
                'closing'        => round($open + $sign * ($debit - $kred), 2),
                'lines'          => $rows,
            ];
        }

        return [
            'accounts'   => $result,
            'line_count' => $lineCount,
            'totals'     => ['debit' => round($grandDebit, 2), 'kredit' => round($grandKredit, 2)],
            'truncated'  => $truncated,
        ];
    }

    /**
     * Pilihan akun untuk filter: "[kode] nama", akun induk ditandai.
     *
     * @return array<int, string>
     */
    public function accountOptions(int $companyId): array
    {
        $accounts = Account::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name', 'parent_code']);
        $parents  = $accounts->pluck('parent_code')->filter()->flip();

        return $accounts->mapWithKeys(fn ($a) => [
            $a->id => "[{$a->code}] {$a->name}" . (isset($parents[$a->code]) ? ' — semua sub-akun' : ''),
        ])->all();
    }

    /**
     * Satu akun transaksi, gabungan sub-akun dari akun induk, atau semua akun
     * PT. Akun induk ikut dihitung karena data lama bisa punya jurnal langsung.
     */
    private function accountsInScope(int $companyId, ?int $accountId): Collection
    {
        $q = Account::withoutGlobalScopes()->where('company_id', $companyId);

        if ($accountId !== null) {
            $acc = (clone $q)->find($accountId);
            if (! $acc) {
                return collect();
            }
            $q->whereIn('id', Account::descendantIds($acc->code, $companyId, includeSelf: true));
        }

        return $q->orderBy('code')->get(['id', 'code', 'name', 'normal_balance']);
    }

    private function baseQuery(int $companyId, array $accountIds): Builder
    {
        return DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->whereIn('jl.account_id', $accountIds);
    }
}
