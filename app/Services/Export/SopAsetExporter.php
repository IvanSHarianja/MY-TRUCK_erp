<?php

namespace App\Services\Export;

use App\Models\Account;
use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "SOP Aset & Workshop - Armada.xlsx": Master Unit, Master Karyawan,
 * Pemeliharaan, Penyusutan + jembatan Ritase & Jam Kerja (pemakaian unit
 * untuk penyusutan per rit/jam).
 *
 * Penyusutan diambil dari BUKU BESAR (jurnal DEP-/DEPUSE- yang posted),
 * bukan dihitung ulang rumus SOP: baris per unit per bulan dalam periode,
 * dengan Akumulasi Sebelumnya dari seluruh riwayat sebelum bulan itu.
 */
class SopAsetExporter extends SopWorkbookExporter
{
    public const PEMELIHARAAN_LAST_ROW = 2011;
    public const PENYUSUTAN_LAST_ROW   = 3011;
    public const LOG_LAST_ROW          = 3011;

    public function __construct(private SopMasterData $master) {}

    public function file(): SopFile
    {
        return SopFile::Aset;
    }

    public function buildPayload(Company $company, CarbonInterface $dari, CarbonInterface $sampai, ?string $exportedBy = null): array
    {
        [$dari, $sampai, $dariStr, $untilStr] = $this->period($dari, $sampai);
        $cid = $company->id;

        $units = $this->master->units($cid, $untilStr);
        $this->assertCapacity('Master Unit', $units->count(), SopMasterData::UNIT_LAST_ROW);
        $employees = $this->master->employees($cid);
        $this->assertCapacity('Master Karyawan', $employees->count(), SopMasterData::KARYAWAN_LAST_ROW);

        [$pemeliharaan, $nMaint]   = $this->maintenance($cid, $dariStr, $untilStr);
        [$penyusutan, $nDep]       = $this->depreciation($cid, $dari, $untilStr);
        [$ritase, $nRit]           = $this->ritaseMirror($cid, $dariStr, $untilStr);
        [$jamKerja, $nJam]         = $this->jamKerjaMirror($cid, $dariStr, $untilStr);

        return [
            'cells' => [
                'Master Unit'     => $this->master->unitCells($cid, $untilStr, mirror: false),
                'Master Karyawan' => $this->master->employeeCells($cid, mirror: false),
                'Pemeliharaan'    => $pemeliharaan,
                'Penyusutan'      => $penyusutan,
                'Ritase'          => $ritase,
                'Jam Kerja'       => $jamKerja,
                'Parameter'       => $this->parameterCells($company, $dari, $sampai, $exportedBy,
                    'Penyusutan & akumulasi diambil dari jurnal MY-TRUCK (posted). '
                    . 'Sheet Ritase & Jam Kerja = salinan statis pemakaian unit dalam periode.'),
            ],
            'clear'    => [],
            'counts'   => [
                'unit'         => $units->count(),
                'karyawan'     => $employees->count(),
                'pemeliharaan' => $nMaint,
                'penyusutan'   => $nDep,
                'ritase'       => $nRit,
                'jam kerja'    => $nJam,
            ],
            'warnings' => [],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function maintenance(int $cid, string $dariStr, string $untilStr): array
    {
        $logs = DB::table('asset_maintenance_logs as m')
            ->join('assets as s', 's.id', '=', 'm.asset_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'm.vendor_id')
            ->where('m.company_id', $cid)
            ->where('m.maintenance_date', '>=', $dariStr)
            ->where('m.maintenance_date', '<', $untilStr)
            ->orderBy('m.maintenance_date')->orderBy('m.id')
            ->select('m.*', 's.asset_code', 'v.name as vendor_name')
            ->get();

        $this->assertCapacity('Pemeliharaan', $logs->count(), self::PEMELIHARAAN_LAST_ROW);

        // Cara bayar dari jurnalnya: ada baris kas/bank = Tunai, selain itu Kredit (utang).
        $cashIds    = Account::cashAccountIds($cid);
        $paidByCash = DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', $logs->pluck('journal_entry_id')->filter()->all())
            ->whereIn('account_id', $cashIds)
            ->distinct()
            ->pluck('journal_entry_id')
            ->flip();

        $items = DB::table('asset_maintenance_log_items as i')
            ->leftJoin('materials as mt', 'mt.id', '=', 'i.material_id')
            ->whereIn('i.asset_maintenance_log_id', $logs->pluck('id')->all())
            ->select('i.asset_maintenance_log_id', 'i.qty', 'mt.name', 'mt.satuan')
            ->get()
            ->groupBy('asset_maintenance_log_id');

        $cells = [];
        $row   = self::FIRST_ROW;
        foreach ($logs as $m) {
            $spareparts = ($items[$m->id] ?? collect())
                ->map(fn ($i) => trim("{$i->name} × " . (float) $i->qty . " {$i->satuan}"))
                ->implode(', ');

            $cells += $this->dateParts('E', $row, $m->maintenance_date);
            $cells += $this->dateParts('S', $row, $m->next_service_date);
            $cells += [
                "H{$row}" => (string) $m->asset_code,
                "K{$row}" => SopLookups::JENIS_PEMELIHARAAN[$m->type] ?? null,
                "L{$row}" => trim($m->description . ($spareparts !== '' ? " | Sparepart: {$spareparts}" : '')),
                "M{$row}" => $m->vendor_name,
                "N{$row}" => $this->money($m->cost),
                "O{$row}" => $m->journal_entry_id ? (isset($paidByCash[$m->journal_entry_id]) ? 'Tunai' : 'Kredit') : null,
                "P{$row}" => $m->hm_saat_service !== null ? (float) $m->hm_saat_service : null,
                "Q{$row}" => $m->next_service_hm !== null ? (float) $m->next_service_hm : null,
                "V{$row}" => $m->photo_url,
                "W{$row}" => SopLookups::APPROVED,
                "Y{$row}" => trim(($m->notes ? "{$m->notes} • " : '') . "Jurnal BBK-MT-{$m->id}"),
            ];
            $row++;
        }

        return [$cells, $logs->count()];
    }

    /**
     * Baris penyusutan per unit per bulan dari jurnal posted (non-pembalik)
     * di akun akumulasi yang bertag aset. Kolom N & P (dihitung / bulan ini)
     * dan O (akumulasi sebelumnya) DITIMPA angka buku besar — rumus SOP
     * menghitung ulang dari Master Unit & menjumlah baris di file saja.
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function depreciation(int $cid, Carbon $dari, string $untilStr): array
    {
        $monthly = $this->postedLines($cid)
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('assets as s', 's.id', '=', 'jl.asset_id')
            ->where('je.entry_date', '<', $untilStr)
            ->where('a.category', 'aset')
            ->where('a.sub_category', 'aset_tetap')
            ->where('a.normal_balance', 'kredit')
            ->groupBy('s.asset_code', 'je.period_year', 'je.period_month')
            ->orderBy('s.asset_code')->orderBy('je.period_year')->orderBy('je.period_month')
            ->selectRaw('s.asset_code, je.period_year as y, je.period_month as m, SUM(jl.kredit) - SUM(jl.debit) as amount')
            ->get();

        $firstMonth = $dari->copy()->startOfMonth();
        $rows       = [];
        $running    = [];

        foreach ($monthly as $r) {
            $before = $running[$r->asset_code] ?? 0.0;
            $running[$r->asset_code] = $before + (float) $r->amount;

            if (Carbon::create((int) $r->y, (int) $r->m, 1)->lt($firstMonth) || abs((float) $r->amount) < 0.005) {
                continue;
            }
            $rows[] = [$r, $before];
        }

        // Urut periode lalu unit, seperti input bulanan di SOP.
        usort($rows, fn ($a, $b) => [$a[0]->y, $a[0]->m, $a[0]->asset_code] <=> [$b[0]->y, $b[0]->m, $b[0]->asset_code]);
        $this->assertCapacity('Penyusutan', count($rows), self::PENYUSUTAN_LAST_ROW);

        $cells = [];
        $row   = self::FIRST_ROW;
        foreach ($rows as [$r, $before]) {
            $amount = round((float) $r->amount, 2);
            $cells += [
                "E{$row}" => (int) $r->m,
                "F{$row}" => (int) $r->y,
                "G{$row}" => (string) $r->asset_code,
                "N{$row}" => $amount,
                "O{$row}" => round($before, 2),
                "P{$row}" => $amount,
                "V{$row}" => 'Dari jurnal penyusutan MY-TRUCK',
            ];
            $row++;
        }

        return [$cells, count($rows)];
    }

    /** Jembatan 'Ritase' (A1:AF3011 di file Armada): kolom yang dibaca Penyusutan!K. */
    private function ritaseMirror(int $cid, string $dariStr, string $untilStr): array
    {
        $logs = DB::table('rit_logs as l')
            ->join('armada_contracts as c', 'c.id', '=', 'l.armada_contract_id')
            ->join('assets as s', 's.id', '=', 'l.asset_id')
            ->where('l.company_id', $cid)
            ->where('l.log_date', '>=', $dariStr)->where('l.log_date', '<', $untilStr)
            ->orderBy('l.log_date')->orderBy('l.id')
            ->select('l.log_date', 'c.contract_number', 's.asset_code', 'l.rit_count')
            ->get();
        $this->assertCapacity('Ritase', $logs->count(), self::LOG_LAST_ROW);

        $cells = $this->master->mirrorHeader(['D' => 'Tanggal Kerja', 'H' => 'No Kontrak', 'L' => 'Kode Unit (Dump Truck)', 'P' => 'Jumlah Rit']);
        $row   = self::FIRST_ROW;
        foreach ($logs as $l) {
            $cells += [
                "D{$row}" => Carbon::parse($l->log_date),
                "H{$row}" => $l->contract_number,
                "L{$row}" => (string) $l->asset_code,
                "P{$row}" => (float) $l->rit_count,
            ];
            $row++;
        }

        return [$cells, $logs->count()];
    }

    /** Jembatan 'Jam Kerja' (A1:AF3011 di file Alat Berat). */
    private function jamKerjaMirror(int $cid, string $dariStr, string $untilStr): array
    {
        $logs = DB::table('rental_logs as l')
            ->join('rental_contracts as c', 'c.id', '=', 'l.rental_contract_id')
            ->join('assets as s', 's.id', '=', 'l.asset_id')
            ->where('l.company_id', $cid)
            ->where('l.log_date', '>=', $dariStr)->where('l.log_date', '<', $untilStr)
            ->orderBy('l.log_date')->orderBy('l.id')
            ->select('l.log_date', 'c.contract_number', 's.asset_code', 'l.jam_kerja')
            ->get();
        $this->assertCapacity('Jam Kerja', $logs->count(), self::LOG_LAST_ROW);

        $cells = $this->master->mirrorHeader(['D' => 'Tanggal Kerja', 'H' => 'No Kontrak', 'L' => 'Kode Unit', 'R' => 'Jam Kerja']);
        $row   = self::FIRST_ROW;
        foreach ($logs as $l) {
            $cells += [
                "D{$row}" => Carbon::parse($l->log_date),
                "H{$row}" => $l->contract_number,
                "L{$row}" => (string) $l->asset_code,
                "R{$row}" => (float) $l->jam_kerja,
            ];
            $row++;
        }

        return [$cells, $logs->count()];
    }
}
