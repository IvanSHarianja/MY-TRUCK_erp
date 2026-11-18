<?php

namespace App\Services\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
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
 *   - Periode = bulan dipilih (atau setahun) — sama dengan CashFlowService.
 *   - Setiap rupiah kas dialokasikan ke TEPAT SATU baris (lihat allocate()).
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

        [$startKey, $endKey] = CashFlowService::periodKeys($year, $month);

        // Saldo awal — sama dengan CashFlowService: posisi kas sebelum periode
        // + jurnal saldo_awal di dalam periode.
        $saldoAwal = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->where(function ($q) use ($startKey, $endKey) {
                $q->whereRaw('(je.period_year * 100 + je.period_month) < ?', [$startKey])
                  ->orWhere(fn ($q2) => $q2->where('je.document_type', 'saldo_awal')
                      ->whereRaw('(je.period_year * 100 + je.period_month) <= ?', [$endKey]));
            })
            ->whereIn('jl.account_id', $kasAccountIds)
            ->select(DB::raw('COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.kredit), 0) as net'))
            ->value('net');

        $mapping = config('lak_mapping');
        [$allocated, $unmapped] = $this->allocate($companyId, $startKey, $endKey, $kasAccountIds, $mapping);

        // === PENERIMAAN ===
        $penerimaan = [];
        foreach ($mapping['penerimaan'] as $i => $item) {
            $penerimaan[] = [
                'label'       => $item['label'],
                'description' => $item['description'],
                'amount'      => round($allocated["penerimaan.{$i}"] ?? 0, 2),
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

            $items = [];
            foreach ($mapping[$key]['items'] as $i => $item) {
                $items[] = [
                    'label'       => $item['label'],
                    'description' => $item['description'],
                    'amount'      => round($allocated["{$key}.{$i}"] ?? 0, 2),
                ];
            }

            $pengeluaran[$key] = [
                'title' => $title,
                'items' => $items,
                'total' => array_sum(array_column($items, 'amount')),
            ];
        }

        $totalPengeluaran = array_sum(array_column($pengeluaran, 'total'));
        // Kas yang akunnya belum terpetakan ikut dihitung (baris terpisah di
        // laporan) → saldo akhir LAK selalu = saldo kas sebenarnya.
        $saldoAkhir = $saldoAwal + $totalPenerimaan + $unmapped['in'] - $totalPengeluaran - $unmapped['out'];

        // Posisi riil per rekening. Bisa berbeda dari saldoAkhir LAK kalau ada
        // transaksi kas dengan akun lawan yang role-nya belum ada di lak_mapping —
        // view menampilkan selisihnya supaya tidak hilang diam-diam.
        $posisiKas = $this->cashPosition->getReport($companyId, $year, $month);

        // Kas yang akun lawannya tidak cocok dengan baris LAK mana pun — ditampilkan
        // terpisah supaya selisih dengan Posisi Kas bisa dijelaskan.
        $tidakTerpetakan = ['masuk' => round($unmapped['in'], 2), 'keluar' => round($unmapped['out'], 2)];

        return compact(
            'saldoAwal',
            'penerimaan', 'totalPenerimaan',
            'pengeluaran', 'totalPengeluaran',
            'saldoAkhir',
            'posisiKas',
            'tidakTerpetakan',
        );
    }

    /**
     * Alokasikan setiap rupiah kas di periode ke TEPAT SATU baris LAK.
     *
     * Dulu tiap baris menjumlahkan SELURUH kas dari jurnal yang punya minimal
     * satu akun lawan cocok → satu jurnal Dr BBM 1jt + Dr Maintenance 2jt /
     * Cr Kas 3jt terhitung 3jt di BBM DAN 3jt di Sparepart; gaji ARMD masuk
     * "Supir" sekaligus "Gaji Kantor" (seksi Kantor = semua lini).
     *
     * Sekarang per jurnal:
     *  1. Kas keluar dibagi ke baris lawan DEBIT (kas masuk → lawan KREDIT)
     *     sebanding nominalnya.
     *  2. Tiap bagian masuk ke baris pertama yang cocok peran + arah + lini;
     *     baris berlini spesifik dicek dulu, baru baris tanpa lini (Kantor/Modal).
     *  3. Tidak cocok → "belum terpetakan".
     * Jurnal tanpa akun lawan (transfer antar rekening) dilewati.
     *
     * @return array{0: array<string, float>, 1: array{in: float, out: float}}
     */
    private function allocate(int $companyId, int $startKey, int $endKey, array $kasAccountIds, array $mapping): array
    {
        $lines = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->leftJoin('business_units as bu', 'bu.id', '=', 'je.business_unit_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereNotIn('je.document_type', ['saldo_awal', 'pembalik'])
            ->whereRaw('(je.period_year * 100 + je.period_month) BETWEEN ? AND ?', [$startKey, $endKey])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_entry_lines as k')
                ->whereColumn('k.journal_entry_id', 'je.id')->whereIn('k.account_id', $kasAccountIds))
            ->get(['jl.journal_entry_id', 'jl.account_id', 'jl.debit', 'jl.kredit', 'a.role', 'bu.code as bu_code']);

        $rules = $this->rules($mapping);
        $kas = array_flip($kasAccountIds);
        $out = [];
        $unmapped = ['in' => 0.0, 'out' => 0.0];

        foreach ($lines->groupBy('journal_entry_id') as $entryLines) {
            $kasLines = $entryLines->filter(fn ($l) => isset($kas[$l->account_id]));
            $lawan    = $entryLines->reject(fn ($l) => isset($kas[$l->account_id]));
            if ($lawan->isEmpty()) {
                continue;   // transfer antar rekening
            }
            $buCode = $entryLines->first()->bu_code;

            foreach (['in' => 'debit', 'out' => 'kredit'] as $direction => $kasSide) {
                $kasAmount = (float) $kasLines->sum($kasSide);
                if ($kasAmount <= 0) {
                    continue;
                }

                // Kas keluar ↔ lawan di DEBIT; kas masuk ↔ lawan di KREDIT.
                $lawanSide = $direction === 'out' ? 'debit' : 'kredit';
                $targets = $lawan->filter(fn ($l) => (float) $l->{$lawanSide} > 0);
                if ($targets->isEmpty()) {
                    $targets = $lawan;          // jurnal tak lazim: bagi rata ke semua lawan
                    $weightOf = fn ($l) => 1.0;
                } else {
                    $weightOf = fn ($l) => (float) $l->{$lawanSide};
                }
                $totalWeight = (float) $targets->sum($weightOf);

                foreach ($targets as $t) {
                    $piece = $kasAmount * $weightOf($t) / $totalWeight;
                    $key = $this->matchRule($rules, (string) $t->role, $direction, $buCode);
                    if ($key === null) {
                        $unmapped[$direction] += $piece;
                    } else {
                        $out[$key] = ($out[$key] ?? 0) + $piece;
                    }
                }
            }
        }

        return [$out, $unmapped];
    }

    /**
     * Aturan baris LAK berurutan: lini spesifik dulu, lalu tanpa lini.
     *
     * @return list<array{key: string, roles: array<string, true>, direction: string, bu: ?string}>
     */
    private function rules(array $mapping): array
    {
        $rules = [];
        foreach ($mapping['penerimaan'] as $i => $item) {
            $rules[] = ['key' => "penerimaan.{$i}", 'item' => $item, 'bu' => $item['business_unit_code'] ?? null];
        }
        foreach (['pengeluaran_expedisi', 'pengeluaran_alber', 'pengeluaran_material', 'pengeluaran_borongan', 'pengeluaran_kantor'] as $section) {
            foreach ($mapping[$section]['items'] ?? [] as $i => $item) {
                $rules[] = ['key' => "{$section}.{$i}", 'item' => $item, 'bu' => $mapping[$section]['business_unit_code'] ?? null];
            }
        }

        usort($rules, fn ($a, $b) => ($a['bu'] === null) <=> ($b['bu'] === null));   // stabil: urutan config dipertahankan

        return array_map(fn ($r) => [
            'key'       => $r['key'],
            'roles'     => array_fill_keys(array_map(fn (AccountRole $role) => $role->value, $r['item']['roles']), true),
            'direction' => $r['item']['direction'],
            'bu'        => $r['bu'],
        ], $rules);
    }

    private function matchRule(array $rules, string $role, string $direction, ?string $buCode): ?string
    {
        foreach ($rules as $r) {
            if ($r['direction'] === $direction && isset($r['roles'][$role]) && ($r['bu'] === null || $r['bu'] === $buCode)) {
                return $r['key'];
            }
        }

        return null;
    }

    /**
     * ID semua akun kas + kas kecil untuk tenant ini — definisi bersama semua laporan kas.
     */
    private function kasAccountIds(int $companyId): array
    {
        return Account::cashAccountIds($companyId);
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
