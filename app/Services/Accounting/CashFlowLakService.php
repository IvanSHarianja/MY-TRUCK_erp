<?php

namespace App\Services\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\BusinessUnit;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Arus Kas — Format Operasional (mirror layout Excel LAK ALBER & EXP).
 *
 * BEDA DENGAN CashFlowService:
 *   - CashFlowService  = format PSAK (Operasi/Investasi/Pendanaan) untuk audit.
 *   - CashFlowLakService = format manajemen harian: Penerimaan + Pengeluaran per lini,
 *     line item detail (BIAYA BBM, BIAYA SUPIR, dst) mirror kebiasaan Excel legacy.
 *
 * DUA-DUANYA hidup berdampingan — beda audiens.
 *
 * SUMBER DATA: journal_entry_lines yang menyentuh akun KAS (role=cash|cash_petty).
 * MAPPING baris: config('lak_mapping').
 *
 * KENAPA share pattern dengan CashFlowService:
 *   - Exclude 'saldo_awal' & 'pembalik' — konsisten.
 *   - Skip jurnal transfer antar-kas (BCA→Mandiri) — hindari double count.
 *   - Cutoff periode <= (year, month) — cumulative-to-date sampai bulan yang dipilih.
 */
class CashFlowLakService
{
    public function __construct(private CashPositionService $cashPosition) {}

    /**
     * @return array{
     *   saldoAwal: float,
     *   penerimaan: array<int, array{label:string,description:string,amount:float}>,
     *   totalPenerimaan: float,
     *   pengeluaran: array<string, array{title:string,items:array,total:float}>,
     *   totalPengeluaran: float,
     *   saldoAkhir: float,
     * }
     */
    public function getReport(int $companyId, int $year, ?int $month = null): array
    {
        $kasAccountIds = $this->kasAccountIds($companyId);

        if (empty($kasAccountIds)) {
            return $this->emptyReport();
        }

        // Saldo awal — konsisten dengan CashFlowService: hanya jurnal document_type='saldo_awal'
        // di akun kas. Jurnal ini di-post sekali di awal tahun/pindah data.
        $saldoAwal = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', 'saldo_awal')
            ->whereIn('jl.account_id', $kasAccountIds)
            ->select(DB::raw('COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.kredit), 0) as net'))
            ->value('net');

        $mapping = config('lak_mapping');
        $businessUnitMap = $this->businessUnitMap($companyId);

        // === PENERIMAAN ===
        $penerimaan = [];
        foreach ($mapping['penerimaan'] as $item) {
            $amount = $this->sumForLine(
                companyId:      $companyId,
                year:           $year,
                month:          $month,
                kasAccountIds:  $kasAccountIds,
                roles:          $item['roles'],
                businessUnitId: $businessUnitMap[$item['business_unit_code']] ?? null,
                direction:      $item['direction'],
            );
            $penerimaan[] = [
                'label'       => $item['label'],
                'description' => $item['description'],
                'amount'      => $amount,
            ];
        }
        $totalPenerimaan = array_sum(array_column($penerimaan, 'amount'));

        // === PENGELUARAN per section ===
        $pengeluaran = [];
        $sectionKeys = [
            'pengeluaran_expedisi'  => 'Pengeluaran Expedisi',
            'pengeluaran_alber'     => 'Pengeluaran Alat Berat',
            'pengeluaran_material'  => 'Pengeluaran Material',
            'pengeluaran_borongan'  => 'Pengeluaran Borongan',
            'pengeluaran_kantor'    => 'Pengeluaran Kantor',
        ];

        foreach ($sectionKeys as $key => $title) {
            if (! isset($mapping[$key])) continue;

            $sectionCfg = $mapping[$key];
            $buId = $sectionCfg['business_unit_code']
                ? ($businessUnitMap[$sectionCfg['business_unit_code']] ?? null)
                : null;

            $items = [];
            foreach ($sectionCfg['items'] as $item) {
                $amount = $this->sumForLine(
                    companyId:      $companyId,
                    year:           $year,
                    month:          $month,
                    kasAccountIds:  $kasAccountIds,
                    roles:          $item['roles'],
                    businessUnitId: $buId,
                    direction:      $item['direction'],
                );
                $items[] = [
                    'label'       => $item['label'],
                    'description' => $item['description'],
                    'amount'      => $amount,
                ];
            }

            $pengeluaran[$key] = [
                'title' => $title,
                'items' => $items,
                'total' => array_sum(array_column($items, 'amount')),
            ];
        }

        $totalPengeluaran = array_sum(array_column($pengeluaran, 'total'));
        $saldoAkhir = $saldoAwal + $totalPenerimaan - $totalPengeluaran;

        // Posisi riil per rekening. Bisa berbeda dari saldoAkhir LAK kalau ada
        // transaksi kas dengan akun lawan yang role-nya belum ada di lak_mapping —
        // view menampilkan selisihnya supaya tidak hilang diam-diam.
        $posisiKas = $this->cashPosition->getReport($companyId, $year, $month);

        return compact(
            'saldoAwal',
            'penerimaan', 'totalPenerimaan',
            'pengeluaran', 'totalPengeluaran',
            'saldoAkhir',
            'posisiKas',
        );
    }

    /**
     * Sum satu baris LAK dari journal_entry_lines.
     *
     * Logika:
     *  - Cari semua jurnal posted (bukan saldo_awal, bukan pembalik) periode <= cutoff
     *  - Yang line-nya menyentuh akun KAS (role=cash|cash_petty)
     *  - Sisi lawan (non-kas) harus di akun ber-role yang di-mapping ke baris ini
     *  - Filter BU journal_entry (bukan BU line — konsisten dengan konvensi MY-TRUCK)
     *  - direction='in'  → sum debit akun kas (kas masuk)
     *  - direction='out' → sum kredit akun kas (kas keluar)
     *
     * Exclude transfer antar-kas: kalau lawan lines TIDAK ada yang match role target,
     * baris ini tidak kena. Otomatis skip jurnal transfer BCA→Mandiri.
     *
     * @param array<int, AccountRole> $roles
     */
    private function sumForLine(
        int $companyId,
        int $year,
        ?int $month,
        array $kasAccountIds,
        array $roles,
        ?int $businessUnitId,
        string $direction,
    ): float {
        $roleValues = array_map(fn (AccountRole $r) => $r->value, $roles);

        // Sub-query: journal_entry_line yang match role target (di sisi LAWAN kas)
        $matchingJournalIds = DB::table('journal_entry_lines as jl2')
            ->join('journal_entries as je2', 'je2.id', '=', 'jl2.journal_entry_id')
            ->join('accounts as a2', 'a2.id', '=', 'jl2.account_id')
            ->where('je2.company_id', $companyId)
            ->where('je2.status', 'posted')
            ->whereNotIn('je2.document_type', ['saldo_awal', 'pembalik'])
            ->whereIn('a2.role', $roleValues)
            ->whereNotIn('jl2.account_id', $kasAccountIds)  // benar-benar sisi lawan
            ->when($businessUnitId, fn ($q) => $q->where('je2.business_unit_id', $businessUnitId))
            ->when(! $businessUnitId, function ($q) {
                // Kalau BU=null (contoh: Modal, Kantor) → jurnal boleh BU apa saja atau NULL.
                // Tidak apply filter.
            })
            ->select('je2.id')
            ->distinct();

        // Main query: sum debit/kredit di akun kas untuk jurnal-jurnal yang match di atas
        $sumColumn = $direction === 'in' ? 'jl.debit' : 'jl.kredit';

        $q = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereNotIn('je.document_type', ['saldo_awal', 'pembalik'])
            ->whereIn('jl.account_id', $kasAccountIds)
            ->whereIn('je.id', $matchingJournalIds);

        // Cutoff periode: kumulatif dari awal tahun sampai (year, month).
        // Konsisten dengan CashFlowService — cumulative ytd.
        $q->where(function ($sub) use ($year, $month) {
            $sub->where('je.period_year', '<', $year)
                ->orWhere(function ($sub2) use ($year, $month) {
                    $sub2->where('je.period_year', $year);
                    if ($month !== null) {
                        $sub2->where('je.period_month', '<=', $month);
                    }
                });
        });

        return (float) $q->sum(DB::raw($sumColumn));
    }

    /**
     * ID semua akun kas + kas kecil untuk tenant ini — definisi bersama semua laporan kas.
     */
    private function kasAccountIds(int $companyId): array
    {
        return Account::cashAccountIds($companyId);
    }

    /**
     * Map code → id business_unit untuk lookup cepat.
     * Return array ['RENT' => 1, 'ARMD' => 2, ...]
     */
    private function businessUnitMap(int $companyId): array
    {
        return BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('id', 'code')
            ->all();
    }

    private function emptyReport(): array
    {
        return [
            'saldoAwal'        => 0.0,
            'penerimaan'       => [],
            'totalPenerimaan'  => 0.0,
            'pengeluaran'      => [],
            'totalPengeluaran' => 0.0,
            'saldoAkhir'       => 0.0,
            'posisiKas'        => ['rows' => [], 'total' => [
                'saldo_awal' => 0.0, 'masuk' => 0.0, 'keluar' => 0.0,
                'transfer_masuk' => 0.0, 'transfer_keluar' => 0.0, 'saldo_akhir' => 0.0,
            ]],
        ];
    }
}
