<?php

namespace App\Services\Export;

use App\Models\Company;
use App\Services\Accounting\OperationalCostService;
use App\Services\Export\Concerns\BuildsFleetSheets;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "SOP Operasional Alat Berat - Armada.xlsx": Kontrak Rental, Jam Kerja,
 * Tagih Rental + jembatan Master Unit/Karyawan/Klien.
 *
 * Jam Kerja ditulis dari kolom jam_kerja MY-TRUCK (menimpa rumus HM akhir −
 * HM awal) dan biaya dari OperationalCostService — sama dengan jurnal
 * BBK-RL MY-TRUCK.
 */
class SopAlatBeratExporter extends SopWorkbookExporter
{
    use BuildsFleetSheets;

    public const KONTRAK_LAST_ROW = 311;
    public const JAM_LAST_ROW     = 3011;
    public const TAGIH_LAST_ROW   = 1011;

    private const T = ['table' => 'rental_contracts', 'logTable' => 'rental_logs', 'fk' => 'rental_contract_id', 'source' => 'rental_contract'];

    public function __construct(private SopMasterData $master, private OperationalCostService $costs) {}

    public function file(): SopFile
    {
        return SopFile::AlatBerat;
    }

    public function buildPayload(Company $company, CarbonInterface $dari, CarbonInterface $sampai, ?string $exportedBy = null): array
    {
        [$dari, $sampai, $dariStr, $untilStr] = $this->period($dari, $sampai);
        $cid = $company->id;

        $contracts = $this->contractsInScope(self::T, $cid, $dariStr, $untilStr);
        $this->assertCapacity('Kontrak Rental', $contracts->count(), self::KONTRAK_LAST_ROW);
        [$totalJam, $billedJam] = $this->unitTotals(self::T, 'jam_kerja', $cid, $untilStr);
        $assetCodes = DB::table('assets')->where('company_id', $cid)->pluck('asset_code', 'id');

        $kontrak = [];
        $row = self::FIRST_ROW;
        foreach ($contracts as $c) {
            $common = $this->contractCommon($c);
            $kontrak += $this->dateParts('E', $row, $common['start']);
            $kontrak += $this->dateParts('AB', $row, $common['end']);
            $kontrak += [
                "C{$row}"  => $c->contract_number,      // timpa rumus RN-NNN
                "H{$row}"  => $c->client_code,
                "J{$row}"  => isset($assetCodes[$c->asset_id]) ? (string) $assetCodes[$c->asset_id] : null,
                "L{$row}"  => $c->lokasi_kerja,
                "M{$row}"  => $common['tipe'],
                "N{$row}"  => $this->money($c->tarif_per_jam),
                "O{$row}"  => $c->bbm_liter_per_jam !== null ? (float) $c->bbm_liter_per_jam : null,
                "P{$row}"  => $this->money($c->harga_bbm_per_liter),
                "R{$row}"  => $this->money($c->gaji_operator_per_hari),
                "S{$row}"  => $this->money($c->uang_makan_per_hari),
                "T{$row}"  => $this->money($c->premi_per_jam),
                "U{$row}"  => $common['status'],
                "V{$row}"  => SopLookups::APPROVED,
                "W{$row}"  => round((float) ($totalJam[$c->id] ?? 0), 2),
                "X{$row}"  => round((float) ($billedJam[$c->id] ?? 0), 2),
                "AF{$row}" => $c->notes,
            ];
            $row++;
        }

        // ── Jam Kerja ───────────────────────────────────────────────────
        $logs = DB::table('rental_logs as l')
            ->join('rental_contracts as c', 'c.id', '=', 'l.rental_contract_id')
            ->leftJoin('employees as e', 'e.id', '=', 'l.operator_id')
            ->where('l.company_id', $cid)
            ->where('l.log_date', '>=', $dariStr)->where('l.log_date', '<', $untilStr)
            ->orderBy('l.log_date')->orderBy('l.id')
            ->select('l.*', 'c.contract_number', 'c.tipe_rental', 'c.bbm_liter_per_jam', 'c.harga_bbm_per_liter',
                'c.gaji_operator_per_hari', 'c.uang_makan_per_hari', 'c.premi_per_jam', 'e.employee_id as operator_code')
            ->get();
        $this->assertCapacity('Jam Kerja', $logs->count(), self::JAM_LAST_ROW);

        $activeInvoices = $this->activeInvoiceIds($cid);
        $jam = [];
        $row = self::FIRST_ROW;
        foreach ($logs as $l) {
            $override = (bool) $l->override_biaya;
            $cost = $this->costs->calculateRentalCost([
                'jam_kerja'              => $l->jam_kerja,
                'override_biaya'         => $override,
                'include_bbm'            => $l->tipe_rental === 'all_in',
                'include_operator'       => in_array($l->tipe_rental, ['all_in', 'semi'], true),
                'bbm_liter_per_jam'      => $l->bbm_liter_per_jam,
                'harga_bbm_per_liter'    => $this->costs->resolveHargaBbm($l->harga_bbm_per_liter, $company),
                'gaji_operator_per_hari' => $l->gaji_operator_per_hari,
                'uang_makan_per_hari'    => $l->uang_makan_per_hari,
                'premi_per_jam'          => $l->premi_per_jam,
                'override_solar_liter'   => $l->solar_liter,
                'override_uang_makan'    => $l->uang_makan_operator,
                'override_premi'         => $l->premi_operator,
            ]);

            $jam += $this->dateParts('E', $row, $l->log_date);
            $jam += [
                "H{$row}"  => $l->contract_number,
                "N{$row}"  => $l->operator_code,
                "P{$row}"  => (float) $l->hm_awal,
                "Q{$row}"  => (float) $l->hm_akhir,
                "R{$row}"  => (float) $l->jam_kerja,       // timpa rumus HM: jam MY-TRUCK
                "S{$row}"  => $this->yesNo($override),
                "T{$row}"  => $override ? $this->money($l->solar_liter) : null,
                "U{$row}"  => $override ? $this->money($l->uang_makan_operator) : null,
                "V{$row}"  => $override ? $this->money($l->premi_operator) : null,
                "W{$row}"  => $cost['bbm'],
                "X{$row}"  => $cost['gaji'],
                "Y{$row}"  => $cost['makan'],
                "Z{$row}"  => $cost['premi'],
                "AC{$row}" => $l->invoice_id && isset($activeInvoices[$l->invoice_id]) ? 'Sudah Ditagih' : 'Belum Ditagih',
                "AF{$row}" => trim(implode(' • ', array_filter([$l->notes, $l->voucher_solar ? "Voucher solar: {$l->voucher_solar}" : null]))) ?: null,
            ];
            $row++;
        }

        [$tagih, $nTagih] = $this->billingCells(self::T, 'jam_kerja', $cid, $dariStr, $untilStr, self::TAGIH_LAST_ROW);

        return [
            'cells' => [
                'Kontrak Rental'  => $kontrak,
                'Jam Kerja'       => $jam,
                'Tagih Rental'    => $tagih,
                'Master Unit'     => $this->master->unitCells($cid, $untilStr, mirror: true),
                'Master Karyawan' => $this->master->employeeCells($cid, mirror: true),
                'Master Klien'    => $this->master->clientMirrorCells($cid),
                'Parameter'       => $this->parameterCells($company, $dari, $sampai, $exportedBy,
                    'Jam kerja & biaya = data/perhitungan MY-TRUCK (sama dengan jurnal biaya). Total & jam ditagih per kontrak dihitung s.d. tanggal SAMPAI.'),
            ],
            'clear'    => [],
            'counts'   => ['kontrak' => $contracts->count(), 'jam kerja' => $logs->count(), 'tagih' => $nTagih],
            'warnings' => [],
        ];
    }
}
