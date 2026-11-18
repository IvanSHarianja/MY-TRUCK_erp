<?php

namespace App\Services\Accounting;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

class CashFlowService
{
    public function __construct(private CashPositionService $cashPosition) {}

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
        // Sprint 2.5: role-based lookup (role cash / cash_petty) + fallback kode standar.
        // User bebas pakai kode akun apapun (contoh 1000000-01 Bank BCA) asal role di-set benar.
        $kasAccountIds = Account::cashAccountIds($companyId);

        if (empty($kasAccountIds)) {
            return $this->emptyReport();
        }

        // PERIODE LAPORAN (keputusan 2026-11-17): bulan dipilih → bulan itu saja;
        // tanpa bulan → setahun penuh. Dulu arus kas dihitung kumulatif sejak awal
        // (kas masuk tahun lalu muncul di laporan tahun ini) dan saldo awal
        // mengambil SEMUA jurnal saldo_awal tanpa batas tanggal.
        [$startKey, $endKey] = self::periodKeys($year, $month);

        // Saldo awal = posisi kas sebelum periode (semua jurnal, termasuk saldo_awal)
        //            + jurnal saldo_awal yang tanggalnya di dalam periode.
        // Void: jurnal asli status 'void' & pembaliknya sama-sama tidak dihitung —
        // aturan yang sama dengan Neraca Saldo, jadi saldo akhir = saldo kas TB.
        $saldoAwal = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->whereIn('jl.account_id', $kasAccountIds)
            ->where(function ($q) use ($startKey, $endKey) {
                $q->whereRaw('(je.period_year * 100 + je.period_month) < ?', [$startKey])
                  ->orWhere(fn ($q2) => $q2->where('je.document_type', 'saldo_awal')
                      ->whereRaw('(je.period_year * 100 + je.period_month) <= ?', [$endKey]));
            })
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
            // Exclude pembalik supaya angka arus kas tidak terbalik dari void.
            ->where('je.document_type', '!=', 'pembalik')
            ->whereRaw('(je.period_year * 100 + je.period_month) BETWEEN ? AND ?', [$startKey, $endKey])
            ->whereIn('jl.account_id', $kasAccountIds)
            ->select('je.id', 'jl.debit', 'jl.kredit')
            ->get();

        // Kategori akun lawan semua jurnal sekaligus (dulu 1 query per baris kas).
        $lawanByEntry = DB::table('journal_entry_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('jl.journal_entry_id', $journalsWithKas->pluck('id')->unique()->all())
            ->whereNotIn('jl.account_id', $kasAccountIds)
            ->get(['jl.journal_entry_id', 'a.cash_flow_category'])
            ->groupBy('journal_entry_id');

        foreach ($journalsWithKas as $row) {
            $kasMasuk  = (float) $row->debit;
            $kasKeluar = (float) $row->kredit;

            if ($kasMasuk == 0 && $kasKeluar == 0) {
                continue;
            }

            // Lihat lawan akun di entry ini: semua line non-kas
            $lawanCategories = collect($lawanByEntry->get($row->id, []))->pluck('cash_flow_category');

            // BUG-21: Kalau tidak ada lawan non-kas → jurnal ini transfer
            // antar akun kas (BCA→Mandiri, dll). Skip supaya tidak dobel-count
            // di laporan Arus Kas (kedua sisi kas bakal iterated). Mutasinya
            // tetap terlihat di bagian Posisi Kas per Rekening.
            //
            // Cek "ada line non-kas", BUKAN "ada kategori terisi": akun lawan yang
            // cash_flow_category-nya kosong (mis. hasil import COA Excel) dulu
            // ikut dianggap transfer → kas masuk/keluarnya hilang dari laporan.
            if ($lawanCategories->isEmpty()) {
                continue;
            }

            // first() setelah filter() — dulu pakai [0], padahal filter() tidak
            // me-reset key: kategori [null, 'operasi'] → [1 => 'operasi'] → [0] undefined.
            $category = $lawanCategories->filter()->first() ?? 'operasi';

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

        $posisiKas = $this->cashPosition->getReport($companyId, $year, $month);

        return compact(
            'saldoAwal',
            'kasMasukOperasi',   'kasKeluarOperasi',
            'kasMasukInvestasi', 'kasKeluarInvestasi',
            'kasMasukPendanaan', 'kasKeluarPendanaan',
            'totalKasMasuk',     'totalKasKeluar',
            'kenaikanBersih',    'saldoAkhir',
            'posisiKas',
        );
    }

    /**
     * Batas periode laporan kas sebagai kunci YYYYMM: bulan dipilih → bulan itu,
     * tanpa bulan → Januari–Desember tahun itu. Dipakai juga oleh Posisi Kas & LAK.
     *
     * @return array{0: int, 1: int}
     */
    public static function periodKeys(int $year, ?int $month): array
    {
        return $month !== null
            ? [$year * 100 + $month, $year * 100 + $month]
            : [$year * 100 + 1, $year * 100 + 12];
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
            'posisiKas'           => ['rows' => [], 'total' => [
                'saldo_awal' => 0.0, 'masuk' => 0.0, 'keluar' => 0.0,
                'transfer_masuk' => 0.0, 'transfer_keluar' => 0.0, 'saldo_akhir' => 0.0,
            ]],
        ];
    }
}
