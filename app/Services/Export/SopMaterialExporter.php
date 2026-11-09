<?php

namespace App\Services\Export;

use App\Models\Company;
use App\Models\MaterialPurchase;
use App\Models\MaterialSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "SOP Material - Armada.xlsx": Master Material, Pembelian Material,
 * Penjualan Material + jembatan Master Klien/Vendor.
 *
 * Stok & MAC diambil dari material_stock_movements (sumber kebenaran
 * persediaan perpetual MY-TRUCK), bukan dihitung ulang rumus SOP:
 *   - Stok Awal / Harga Pokok Awal = posisi sebelum DARI
 *   - Stok Saat Ini / MAC          = posisi s.d. SAMPAI (menimpa rumus)
 *   - Stok/MAC sebelum & sesudah per transaksi = baris movement-nya
 */
class SopMaterialExporter extends SopWorkbookExporter
{
    public const MATERIAL_LAST_ROW   = 211;
    public const PEMBELIAN_LAST_ROW  = 2011;
    public const PENJUALAN_LAST_ROW  = 3011;

    public function __construct(private SopMasterData $master) {}

    public function file(): SopFile
    {
        return SopFile::Material;
    }

    public function buildPayload(Company $company, CarbonInterface $dari, CarbonInterface $sampai, ?string $exportedBy = null): array
    {
        [$dari, $sampai, $dariStr, $untilStr] = $this->period($dari, $sampai);
        $cid = $company->id;

        $materials = DB::table('materials')->where('company_id', $cid)->orderBy('code')->get();
        $this->assertCapacity('Master Material', $materials->count(), self::MATERIAL_LAST_ROW);

        $movements = DB::table('material_stock_movements')
            ->where('company_id', $cid)
            ->orderBy('movement_date')->orderBy('id')
            ->get();
        $byMaterial = $movements->groupBy('material_id');
        $bySource   = $movements->groupBy(fn ($m) => "{$m->source_type}#{$m->source_id}");
        $cashNames  = DB::table('accounts')->where('company_id', $cid)->pluck('name', 'id');

        // ── Master Material ─────────────────────────────────────────────
        $master = [];
        $row = self::FIRST_ROW;
        foreach ($materials as $m) {
            $hist  = $byMaterial[$m->id] ?? collect();
            $awal  = $this->positionBefore($m, $hist, $dariStr);
            $akhir = $this->positionBefore($m, $hist, $untilStr);

            $master += [
                "C{$row}" => (string) $m->code,          // timpa rumus MAT-NNN
                "D{$row}" => $m->name,
                "E{$row}" => $m->satuan,
                "F{$row}" => $this->money($m->harga_per_satuan),
                "H{$row}" => $awal['stock'],
                "I{$row}" => $awal['mac'],
                "L{$row}" => $akhir['stock'],
                "M{$row}" => $akhir['mac'],
                "P{$row}" => $this->yesNo($m->is_active),
                "Q{$row}" => trim(implode(' • ', array_filter([
                    $m->jenis === 'sparepart' ? 'Jenis: Sparepart (dipakai servis unit)' : null,
                    $m->notes,
                ]))) ?: null,
            ];
            $row++;
        }

        // ── Pembelian ───────────────────────────────────────────────────
        $purchases = DB::table('material_purchases as p')
            ->join('materials as m', 'm.id', '=', 'p.material_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'p.vendor_id')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'p.journal_entry_id')
            ->where('p.company_id', $cid)
            ->where('p.purchase_date', '>=', $dariStr)->where('p.purchase_date', '<', $untilStr)
            ->orderBy('p.purchase_date')->orderBy('p.id')
            ->select('p.*', 'm.code as material_code', 'v.code as vendor_code', 'je.status as journal_status')
            ->get();
        $this->assertCapacity('Pembelian Material', $purchases->count(), self::PEMBELIAN_LAST_ROW);

        $pembelian = [];
        $row = self::FIRST_ROW;
        foreach ($purchases as $p) {
            $mv = $this->movementOf($bySource, MaterialPurchase::class, $p->id, 'in');

            $pembelian += $this->dateParts('E', $row, $p->purchase_date);
            $pembelian += [
                "C{$row}" => $p->purchase_number,         // timpa rumus PB-: nomor MY-TRUCK
                "H{$row}" => (string) $p->material_code,
                "L{$row}" => (float) $p->qty,
                "M{$row}" => $this->money($p->unit_price),
                "N{$row}" => $this->money($p->total_amount),
                "O{$row}" => $p->payment_method === 'kredit' ? 'Kredit' : 'Tunai',
                "P{$row}" => $p->cash_account_id ? ($cashNames[$p->cash_account_id] ?? null) : null,
                "Q{$row}" => $p->vendor_code,
                "T{$row}" => $mv ? (float) $mv->stock_before : null,
                "U{$row}" => $mv ? (float) $mv->mac_before : null,
                "V{$row}" => $mv ? (float) $mv->mac_after : null,
                "Y{$row}" => trim(implode(' • ', array_filter([
                    $p->journal_status === 'void' ? 'JURNAL VOID — stok sudah dikoreksi di MY-TRUCK' : null,
                    $p->notes,
                ]))) ?: null,
            ];
            $row++;
        }

        // ── Penjualan ───────────────────────────────────────────────────
        $sales = DB::table('material_sales as s')
            ->join('materials as m', 'm.id', '=', 's.material_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->where('s.company_id', $cid)
            ->where('s.sale_date', '>=', $dariStr)->where('s.sale_date', '<', $untilStr)
            ->orderBy('s.sale_date')->orderBy('s.id')
            ->select('s.*', 'm.code as material_code', 'c.code as client_code')
            ->get();
        $this->assertCapacity('Penjualan Material', $sales->count(), self::PENJUALAN_LAST_ROW);

        $penjualan = [];
        $row = self::FIRST_ROW;
        foreach ($sales as $s) {
            $mv = $this->movementOf($bySource, MaterialSale::class, $s->id, 'out');

            $penjualan += $this->dateParts('E', $row, $s->sale_date);
            $penjualan += [
                "C{$row}" => $s->sale_number,              // timpa rumus PJ-: nomor MY-TRUCK
                "H{$row}" => $s->client_code,
                "J{$row}" => (string) $s->material_code,
                "N{$row}" => (float) $s->volume,
                "O{$row}" => $this->money($s->harga_satuan),
                "Q{$row}" => $this->money($s->total),
                "R{$row}" => $s->metode === 'invoice' ? 'Invoice' : 'Tunai',
                "S{$row}" => $s->cash_account_id ? ($cashNames[$s->cash_account_id] ?? null) : null,
                // HPP & stok tersedia dari movement keluar. Penjualan lama tanpa
                // movement (sebelum fitur MAC) → biarkan rumus SOP.
                ...($mv ? ["T{$row}" => (float) $mv->unit_cost, "W{$row}" => (float) $mv->stock_before] : []),
                "Z{$row}" => $s->notes,
            ];
            $row++;
        }

        return [
            'cells' => [
                'Master Material'    => $master,
                'Pembelian Material' => $pembelian,
                'Penjualan Material' => $penjualan,
                'Master Klien'       => $this->master->clientMirrorCells($cid),
                'Master Vendor'      => $this->master->vendorMirrorCells($cid),
                'Parameter'          => $this->parameterCells($company, $dari, $sampai, $exportedBy,
                    'Stok & harga pokok (MAC) dari riwayat pergerakan stok MY-TRUCK: Stok Awal = posisi sebelum DARI, Stok Saat Ini = posisi s.d. SAMPAI.'),
            ],
            'clear'    => [],
            'counts'   => ['material' => $materials->count(), 'pembelian' => $purchases->count(), 'penjualan' => $sales->count()],
            'warnings' => [],
        ];
    }

    /**
     * Posisi stok & MAC sebelum tanggal $beforeStr (eksklusif):
     *   - movement terakhir sebelum tanggal → stock_after / mac_after
     *   - belum ada, tapi ada movement sesudahnya → stock_before / mac_before movement pertama
     *   - material tanpa riwayat (data lama) → current_stock / current_mac (fallback harga_pokok)
     *
     * @return array{stock: float, mac: float}
     */
    private function positionBefore(object $material, Collection $history, string $beforeStr): array
    {
        $last = $history->filter(fn ($m) => $m->movement_date < $beforeStr)->last();
        if ($last) {
            return ['stock' => (float) $last->stock_after, 'mac' => (float) $last->mac_after];
        }

        $first = $history->first();
        if ($first) {
            return ['stock' => (float) $first->stock_before, 'mac' => (float) $first->mac_before];
        }

        $mac = (float) $material->current_mac > 0 ? (float) $material->current_mac : (float) $material->harga_pokok;

        return ['stock' => (float) $material->current_stock, 'mac' => $mac];
    }

    private function movementOf(Collection $bySource, string $class, int $id, string $type): ?object
    {
        return ($bySource["{$class}#{$id}"] ?? collect())->firstWhere('movement_type', $type);
    }
}
