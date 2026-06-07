<?php

namespace App\Services\Accounting;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

class CashFlowService
{
    public function __construct(private TrialBalanceService $trialBalance) {}

    /**
     * Laporan Arus Kas metode langsung.
     *
     * Logic:
     *  - Cari ID akun "Kas dan Bank" (kode 111100) + Kas Kecil (111110)
     *  - Sum debit (kas masuk) & kredit (kas keluar) untuk akun-akun tersebut
     *  - Pisahkan transaksi 'saldo_awal' (document_type) sebagai saldo awal
     *  - Group berdasarkan cash_flow_category lawan akun (operasi/investasi/pendanaan)
     *
     * @return array{
     *   saldoAwal: float,
     *   kasMasukOperasi: float,
     *   kasKeluarOperasi: float,
     *   kasMasukInvestasi: float,
     *   kasKeluarInvestasi: float,
     *   kasMasukPendanaan: float,
     *   kasKeluarPendanaan: float,
     *   totalKasMasuk: float,
     *   totalKasKeluar: float,
     *   kenaikanBersih: float,
     *   saldoAkhir: float
     * }
     */
    public function getReport(int $companyId, int $year, ?int $month = null): array
    {
        $kasAccountIds = Account::where('company_id', $companyId)
            ->whereIn('code', ['111100', '111110'])
            ->pluck('id')
            ->toArray();

        if (empty($kasAccountIds)) {
            return $this->emptyReport();
        }

        // Saldo awal = sum dari jurnal dengan document_type = 'saldo_awal' di akun kas
        $saldoAwal = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', 'saldo_awal')
            ->whereIn('jl.account_id', $kasAccountIds)
            ->select(DB::raw('COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.kredit), 0) as net'))
            ->value('net');

        // Untuk arus kas, kita perlu lihat LAWAN akun (kalau di-debit akun kas, lawan akun ada di kredit dengan amount yang sama)
        // Simplifikasi: per jurnal entry, jika ada line kas yang debit > 0 → kas masuk.
        // Kategori-nya dilihat dari lawan akun di entry yang sama.

        $kasMasukOperasi    = 0.0;
        $kasKeluarOperasi   = 0.0;
        $kasMasukInvestasi  = 0.0;
        $kasKeluarInvestasi = 0.0;
        $kasMasukPendanaan  = 0.0;
        $kasKeluarPendanaan = 0.0;

        // Ambil semua jurnal entry yang menyentuh akun kas (kecuali saldo_awal)
        $journalsWithKas = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'saldo_awal')
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
            ->whereIn('jl.account_id', $kasAccountIds)
            ->select('je.id', 'jl.debit', 'jl.kredit')
            ->get();

        foreach ($journalsWithKas as $row) {
            $kasMasuk  = (float) $row->debit;
            $kasKeluar = (float) $row->kredit;

            if ($kasMasuk == 0 && $kasKeluar == 0) {
                continue;
            }

            // Lihat lawan akun di entry ini: ambil semua line non-kas
            $lawanCategories = DB::table('journal_entry_lines as jl')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('jl.journal_entry_id', $row->id)
                ->whereNotIn('jl.account_id', $kasAccountIds)
                ->pluck('a.cash_flow_category')
                ->filter()
                ->unique()
                ->toArray();

            // Default kategori operasi jika tidak ketemu
            $category = $lawanCategories[0] ?? 'operasi';

            if ($category === 'non_kas') {
                $category = 'operasi';
            }

            match ($category) {
                'operasi'   => $kasMasuk > 0 ? $kasMasukOperasi   += $kasMasuk : $kasKeluarOperasi   += $kasKeluar,
                'investasi' => $kasMasuk > 0 ? $kasMasukInvestasi += $kasMasuk : $kasKeluarInvestasi += $kasKeluar,
                'pendanaan' => $kasMasuk > 0 ? $kasMasukPendanaan += $kasMasuk : $kasKeluarPendanaan += $kasKeluar,
                default     => null,
            };
        }

        $totalKasMasuk  = $kasMasukOperasi + $kasMasukInvestasi + $kasMasukPendanaan;
        $totalKasKeluar = $kasKeluarOperasi + $kasKeluarInvestasi + $kasKeluarPendanaan;
        $kenaikanBersih = $totalKasMasuk - $totalKasKeluar;
        $saldoAkhir     = $saldoAwal + $kenaikanBersih;

        return compact(
            'saldoAwal',
            'kasMasukOperasi',   'kasKeluarOperasi',
            'kasMasukInvestasi', 'kasKeluarInvestasi',
            'kasMasukPendanaan', 'kasKeluarPendanaan',
            'totalKasMasuk',     'totalKasKeluar',
            'kenaikanBersih',    'saldoAkhir',
        );
    }

    public function getSaldoAkhir(int $companyId, int $year, ?int $month = null): float
    {
        return $this->getReport($companyId, $year, $month)['saldoAkhir'];
    }

    private function emptyReport(): array
    {
        return [
            'saldoAwal'           => 0.0,
            'kasMasukOperasi'     => 0.0, 'kasKeluarOperasi'   => 0.0,
            'kasMasukInvestasi'   => 0.0, 'kasKeluarInvestasi' => 0.0,
            'kasMasukPendanaan'   => 0.0, 'kasKeluarPendanaan' => 0.0,
            'totalKasMasuk'       => 0.0, 'totalKasKeluar'     => 0.0,
            'kenaikanBersih'      => 0.0, 'saldoAkhir'         => 0.0,
        ];
    }
}
