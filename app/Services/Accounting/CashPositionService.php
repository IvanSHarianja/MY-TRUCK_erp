<?php

namespace App\Services\Accounting;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Posisi Kas per Rekening — pelengkap laporan Arus Kas.
 *
 * KENAPA ADA:
 * Arus Kas (PSAK 2) sengaja tidak mencatat pemindahan dana antar rekening
 * milik sendiri (BCA → Mandiri) karena total kas tidak berubah. Akibatnya
 * mutasi itu tidak terlihat sama sekali. Bagian ini menampilkan per rekening:
 * saldo awal, kas masuk, kas keluar, transfer masuk/keluar, saldo akhir.
 * Total transfer masuk selalu = total transfer keluar, jadi total saldo akhir
 * tetap sama dengan Saldo Kas Akhir di Arus Kas.
 *
 * ATURAN DATA (sama dengan CashFlowService supaya bisa direkonsiliasi):
 *   - Hanya jurnal posted; 'pembalik' di-exclude (jurnal asli yang di-void
 *     sudah berstatus 'void', jadi efek void = 0).
 *   - Saldo awal = jurnal ber-Jenis Bukti 'saldo_awal'.
 *   - Mutasi = jurnal lain s.d. periode (year, month) — kumulatif.
 *   - Transfer = jurnal yang SEMUA line-nya akun kas.
 */
class CashPositionService
{
    /**
     * @return array{
     *   rows: array<int, array{code: string, name: string, saldo_awal: float, masuk: float, keluar: float, transfer_masuk: float, transfer_keluar: float, saldo_akhir: float}>,
     *   total: array{saldo_awal: float, masuk: float, keluar: float, transfer_masuk: float, transfer_keluar: float, saldo_akhir: float}
     * }
     */
    public function getReport(int $companyId, int $year, ?int $month = null): array
    {
        $kasIds = Account::cashAccountIds($companyId);
        if (empty($kasIds)) {
            return ['rows' => [], 'total' => $this->emptyTotals()];
        }

        $saldoAwal = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', 'saldo_awal')
            ->whereIn('jl.account_id', $kasIds)
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.kredit), 0) as net')
            ->pluck('net', 'account_id');

        // Jurnal yang punya minimal satu line NON-kas → kas masuk/keluar sungguhan.
        // Jurnal tanpa line non-kas → transfer antar rekening.
        $journalsWithNonKas = DB::table('journal_entry_lines as x')
            ->join('journal_entries as xe', 'xe.id', '=', 'x.journal_entry_id')
            ->where('xe.company_id', $companyId)
            ->whereNotIn('x.account_id', $kasIds)
            ->select('x.journal_entry_id')
            ->distinct();

        $mutasi = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoinSub($journalsWithNonKas, 'nk', 'nk.journal_entry_id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereNotIn('je.document_type', ['saldo_awal', 'pembalik'])
            ->whereIn('jl.account_id', $kasIds)
            ->where(function ($q) use ($year, $month) {
                $q->where('je.period_year', '<', $year)
                    ->orWhere(function ($q2) use ($year, $month) {
                        $q2->where('je.period_year', $year)
                            ->when($month !== null, fn ($q3) => $q3->where('je.period_month', '<=', $month));
                    });
            })
            ->groupBy('jl.account_id')
            ->selectRaw('
                jl.account_id,
                SUM(CASE WHEN nk.journal_entry_id IS NOT NULL THEN jl.debit  ELSE 0 END) as masuk,
                SUM(CASE WHEN nk.journal_entry_id IS NOT NULL THEN jl.kredit ELSE 0 END) as keluar,
                SUM(CASE WHEN nk.journal_entry_id IS NULL     THEN jl.debit  ELSE 0 END) as transfer_masuk,
                SUM(CASE WHEN nk.journal_entry_id IS NULL     THEN jl.kredit ELSE 0 END) as transfer_keluar
            ')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::withoutGlobalScopes()
            ->whereIn('id', $kasIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $rows = [];
        foreach ($accounts as $account) {
            $m = $mutasi->get($account->id);

            $row = [
                'code'            => $account->code,
                'name'            => $account->name,
                'saldo_awal'      => round((float) ($saldoAwal[$account->id] ?? 0), 2),
                'masuk'           => round((float) ($m->masuk ?? 0), 2),
                'keluar'          => round((float) ($m->keluar ?? 0), 2),
                'transfer_masuk'  => round((float) ($m->transfer_masuk ?? 0), 2),
                'transfer_keluar' => round((float) ($m->transfer_keluar ?? 0), 2),
            ];
            $row['saldo_akhir'] = round(
                $row['saldo_awal'] + $row['masuk'] - $row['keluar'] + $row['transfer_masuk'] - $row['transfer_keluar'],
                2,
            );

            // Rekening tanpa aktivitas sama sekali (mis. akun header) tidak ditampilkan.
            if (array_filter(array_slice($row, 2), fn ($v) => $v != 0.0)) {
                $rows[] = $row;
            }
        }

        $total = $this->emptyTotals();
        foreach ($rows as $row) {
            foreach ($total as $key => $_) {
                $total[$key] += $row[$key];
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    private function emptyTotals(): array
    {
        return [
            'saldo_awal'      => 0.0,
            'masuk'           => 0.0,
            'keluar'          => 0.0,
            'transfer_masuk'  => 0.0,
            'transfer_keluar' => 0.0,
            'saldo_akhir'     => 0.0,
        ];
    }
}
