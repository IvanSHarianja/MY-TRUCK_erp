<?php

namespace App\Services\Export;

use App\Models\Company;
use App\Services\Accounting\OperationalCostService;
use App\Services\Export\Concerns\BuildsFleetSheets;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "SOP Operasional Armada - Armada.xlsx": Kontrak Armada, Ritase, Tagih
 * Armada + jembatan Master Unit/Karyawan/Klien.
 *
 * Biaya per ritase (BBM, gaji, uang makan, uang jalan, premi) ditulis dari
 * OperationalCostService — angka yang sama dengan jurnal BBK-RT MY-TRUCK —
 * menimpa rumus SOP.
 */
class SopArmadaExporter extends SopWorkbookExporter
{
    use BuildsFleetSheets;

    public const KONTRAK_LAST_ROW = 311;
    public const RITASE_LAST_ROW  = 3011;
    public const TAGIH_LAST_ROW   = 1011;

    private const T = ['table' => 'armada_contracts', 'logTable' => 'rit_logs', 'fk' => 'armada_contract_id', 'source' => 'armada_contract'];

    public function __construct(private SopMasterData $master, private OperationalCostService $costs) {}

    public function file(): SopFile
    {
        return SopFile::Armada;
    }

    public function buildPayload(Company $company, CarbonInterface $dari, CarbonInterface $sampai, ?string $exportedBy = null): array
    {
        [$dari, $sampai, $dariStr, $untilStr] = $this->period($dari, $sampai);
        $cid = $company->id;

        $contracts = $this->contractsInScope(self::T, $cid, $dariStr, $untilStr);
        $this->assertCapacity('Kontrak Armada', $contracts->count(), self::KONTRAK_LAST_ROW);
        [$totalRit, $billedRit] = $this->unitTotals(self::T, 'rit_count', $cid, $untilStr);
        $gendong = $this->mobilizationTotals($cid, $untilStr);

        $kontrak = [];
        $row = self::FIRST_ROW;
        foreach ($contracts as $c) {
            $common = $this->contractCommon($c);
            $kontrak += $this->dateParts('E', $row, $common['start']);
            $kontrak += $this->dateParts('AA', $row, $common['end']);
            $kontrak += [
                "C{$row}"  => $c->contract_number,      // timpa rumus KA-NNN
                "H{$row}"  => $c->client_code,
                "J{$row}"  => $c->route_description,
                "K{$row}"  => $common['tipe'],
                "L{$row}"  => $this->money($c->tarif_per_rit),
                "M{$row}"  => $c->bbm_liter_per_rit !== null ? (float) $c->bbm_liter_per_rit : null,
                "N{$row}"  => $this->money($c->harga_bbm_per_liter),
                "P{$row}"  => $this->money($c->gaji_supir_per_hari),
                "Q{$row}"  => $this->money($c->uang_makan_per_hari),
                "R{$row}"  => $this->money($c->uang_jalan_per_rit),
                "S{$row}"  => $this->money($c->premi_per_rit),
                "T{$row}"  => $common['status'],
                "U{$row}"  => SopLookups::APPROVED,
                "V{$row}"  => round((float) ($totalRit[$c->id] ?? 0), 2),
                "W{$row}"  => round((float) ($billedRit[$c->id] ?? 0), 2),
                // Template SOP hanya kenal tarif per rit — satuan lain dicatat di
                // Keterangan supaya kolom Tarif tidak salah dibaca.
                "AE{$row}" => $this->withSatuanNote($c->satuan_tarif ?? 'per_rit', implode(' ', array_filter([
                    $gendong[$c->id] ?? null,
                    $c->notes,
                ])) ?: null),
            ];
            $row++;
        }

        // ── Ritase ──────────────────────────────────────────────────────
        $logs = DB::table('rit_logs as l')
            ->join('armada_contracts as c', 'c.id', '=', 'l.armada_contract_id')
            ->join('assets as s', 's.id', '=', 'l.asset_id')
            ->leftJoin('employees as e', 'e.id', '=', 'l.driver_id')
            ->where('l.company_id', $cid)
            ->where('l.log_date', '>=', $dariStr)->where('l.log_date', '<', $untilStr)
            ->orderBy('l.log_date')->orderBy('l.id')
            ->select('l.*', 'c.contract_number', 'c.tipe_kontrak', 'c.satuan_tarif', 'c.bbm_liter_per_rit', 'c.harga_bbm_per_liter',
                'c.gaji_supir_per_hari', 'c.uang_makan_per_hari', 'c.uang_jalan_per_rit', 'c.premi_per_rit',
                's.asset_code', 'e.employee_id as driver_code')
            ->get();
        $this->assertCapacity('Ritase', $logs->count(), self::RITASE_LAST_ROW);

        $activeInvoices = $this->activeInvoiceIds($cid);
        $ritase = [];
        $row = self::FIRST_ROW;
        foreach ($logs as $l) {
            $override = (bool) $l->override_biaya;
            $cost = $this->costs->calculateRitCost([
                'rit_count'            => $l->rit_count,
                'override_biaya'       => $override,
                'include_bbm'          => $l->tipe_kontrak === 'all_in',
                'include_operator'     => in_array($l->tipe_kontrak, ['all_in', 'semi'], true),
                'bbm_liter_per_rit'    => $l->bbm_liter_per_rit,
                'harga_bbm_per_liter'  => $this->costs->resolveHargaBbm($l->harga_bbm_per_liter, $company),
                'gaji_supir_per_hari'  => $l->gaji_supir_per_hari,
                'uang_makan_per_hari'  => $l->uang_makan_per_hari,
                'uang_jalan_per_rit'   => $l->uang_jalan_per_rit,
                'premi_per_rit'        => $l->premi_per_rit,
                'override_solar_liter' => $l->solar_liter,
                'override_uang_jalan'  => $l->uang_jalan_supir,
                'override_uang_makan'  => $l->uang_makan_supir,
                'override_premi'       => $l->premi_supir,
            ]);

            $ritase += $this->dateParts('E', $row, $l->log_date);
            $ritase += [
                "H{$row}"  => $l->contract_number,
                "L{$row}"  => (string) $l->asset_code,
                "N{$row}"  => $l->driver_code,
                "P{$row}"  => (float) $l->rit_count,
                "Q{$row}"  => $this->yesNo($override),
                "R{$row}"  => $override ? $this->money($l->solar_liter) : null,
                "S{$row}"  => $override ? $this->money($l->uang_jalan_supir) : null,
                "T{$row}"  => $override ? $this->money($l->uang_makan_supir) : null,
                "U{$row}"  => $override ? $this->money($l->premi_supir) : null,
                "V{$row}"  => $cost['bbm'],
                "W{$row}"  => $cost['gaji'],
                "X{$row}"  => $cost['makan'],
                "Y{$row}"  => $cost['uang_jalan'],
                "Z{$row}"  => $cost['premi'],
                "AC{$row}" => $l->invoice_id && isset($activeInvoices[$l->invoice_id]) ? 'Sudah Ditagih' : 'Belum Ditagih',
                "AF{$row}" => implode(' ', array_filter([
                    $l->satuan_tarif === 'per_m3' ? 'Volume ' . rtrim(rtrim(number_format((float) $l->volume_m3, 2, ',', '.'), '0'), ',') . ' M³.' : null,
                    $l->satuan_tarif === 'per_ton' ? 'Tonase ' . rtrim(rtrim(number_format((float) $l->tonase, 2, ',', '.'), '0'), ',') . ' ton.' : null,
                    $l->satuan_tarif === 'per_hari' ? ((float) $l->hari_kerja == 0.5 ? '½ hari kerja.' : '1 hari kerja.') : null,
                    $l->notes,
                ])) ?: null,
            ];
            $row++;
        }

        [$tagih, $nTagih] = $this->billingCells(self::T, 'rit_count', $cid, $dariStr, $untilStr, self::TAGIH_LAST_ROW);

        $units = $this->master->units($cid, $untilStr);
        $this->assertCapacity('Master Unit', $units->count(), SopMasterData::UNIT_LAST_ROW);

        return [
            'cells' => [
                'Kontrak Armada'  => $kontrak,
                'Ritase'          => $ritase,
                'Tagih Armada'    => $tagih,
                'Master Unit'     => $this->master->unitCells($cid, $untilStr, mirror: true),
                'Master Karyawan' => $this->master->employeeCells($cid, mirror: true),
                'Master Klien'    => $this->master->clientMirrorCells($cid),
                'Parameter'       => $this->parameterCells($company, $dari, $sampai, $exportedBy,
                    'Biaya ritase = perhitungan MY-TRUCK (sama dengan jurnal biaya). Total & rit ditagih per kontrak dihitung s.d. tanggal SAMPAI.'),
            ],
            'clear'    => [],
            'counts'   => ['kontrak' => $contracts->count(), 'ritase' => $logs->count(), 'tagih' => $nTagih],
            'warnings' => [],
        ];
    }

    /**
     * Ongkos gendong per kontrak s.d. SAMPAI — template SOP tidak punya
     * sheet-nya, jadi diringkas di Keterangan kontrak:
     * "[Ongkos gendong: 3× Rp 7.500.000, belum ditagih Rp 2.500.000]".
     *
     * @return array<int, string> contract_id → teks
     */
    private function mobilizationTotals(int $cid, string $untilStr): array
    {
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        return DB::table('armada_mobilizations as m')
            ->leftJoin('invoices as i', 'i.id', '=', 'm.invoice_id')
            ->where('m.company_id', $cid)
            ->where('m.tanggal', '<', $untilStr)
            ->groupBy('m.armada_contract_id')
            ->selectRaw("m.armada_contract_id, COUNT(*) AS n, SUM(m.amount) AS total,
                SUM(CASE WHEN m.invoice_id IS NULL OR i.status = 'void' THEN m.amount ELSE 0 END) AS belum")
            ->get()
            ->mapWithKeys(fn ($g) => [(int) $g->armada_contract_id => sprintf(
                '[Mobilisasi alat: %d× %s%s]',
                $g->n,
                $rp($g->total),
                (float) $g->belum > 0 ? ', belum ditagih ' . $rp($g->belum) : ', sudah ditagih semua',
            )])
            ->all();
    }

    private function withSatuanNote(string $satuan, ?string $notes): ?string
    {
        $prefix = match ($satuan) {
            'per_m3'   => '[Tarif per M³ — kolom Tarif = Rp/M³, volume di Keterangan Ritase]',
            'per_ton'  => '[Tarif per ton — kolom Tarif = Rp/ton, tonase di Keterangan Ritase]',
            'per_hari' => '[Tarif per hari (rental harian) — kolom Tarif = Rp/hari]',
            default    => null,
        };

        return implode(' ', array_filter([$prefix, $notes])) ?: null;
    }
}
