<?php

namespace App\Services\Export\Concerns;

use App\Services\Export\SopLookups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bagian bersama file SOP Operasional Armada & Alat Berat:
 * kontrak → log harian (rit / jam) → penagihan (invoice dari kontrak).
 *
 * @mixin \App\Services\Export\SopWorkbookExporter
 */
trait BuildsFleetSheets
{
    /**
     * Kontrak yang relevan untuk periode: sudah mulai sebelum SAMPAI dan
     * masih aktif, atau berakhir/punya log/punya invoice di dalam periode.
     * Kontrak lama yang sudah tutup sebelum DARI tidak diekspor.
     *
     * @param  array{table: string, logTable: string, fk: string, source: string}  $t
     */
    protected function contractsInScope(array $t, int $cid, string $dariStr, string $untilStr): Collection
    {
        return DB::table("{$t['table']} as c")
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->where('c.company_id', $cid)
            ->where(fn ($q) => $q->whereNull('c.started_at')->orWhere('c.started_at', '<', $untilStr))
            ->where(function ($q) use ($t, $dariStr, $untilStr) {
                $q->where('c.status', 'aktif')
                  ->orWhereNull('c.ended_at')
                  ->orWhere('c.ended_at', '>=', $dariStr)
                  ->orWhereExists(fn ($e) => $e->selectRaw('1')->from($t['logTable'])
                      ->whereColumn("{$t['logTable']}.{$t['fk']}", 'c.id')
                      ->where('log_date', '>=', $dariStr)->where('log_date', '<', $untilStr))
                  ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('invoices')
                      ->where('invoices.source_type', $t['source'])
                      ->whereColumn('invoices.source_id', 'c.id')
                      ->where('invoice_date', '>=', $dariStr)->where('invoice_date', '<', $untilStr));
            })
            ->orderBy('c.started_at')->orderBy('c.contract_number')
            ->select('c.*', 'cl.code as client_code')
            ->get();
    }

    /**
     * Total satuan (rit/jam) per kontrak s.d. SAMPAI, dan yang sudah ditagih
     * (log ber-invoice yang tidak void). Menimpa rumus SOP yang hanya
     * menjumlah log & tagihan di file (periode export saja).
     *
     * @return array{0: Collection, 1: Collection}  [contract_id → total, contract_id → ditagih]
     */
    protected function unitTotals(array $t, string $unitCol, int $cid, string $untilStr): array
    {
        $base = fn () => DB::table("{$t['logTable']} as l")
            ->where('l.company_id', $cid)
            ->where('l.log_date', '<', $untilStr)
            ->groupBy("l.{$t['fk']}");

        $total = $base()->selectRaw("l.{$t['fk']} as cid, SUM(l.{$unitCol}) as n")->pluck('n', 'cid');
        $billed = $base()
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.status', '!=', 'void')
            ->selectRaw("l.{$t['fk']} as cid, SUM(l.{$unitCol}) as n")
            ->pluck('n', 'cid');

        return [$total, $billed];
    }

    /** Invoice id yang tidak void — untuk kolom Status Tagih log. */
    protected function activeInvoiceIds(int $cid): Collection
    {
        return DB::table('invoices')->where('company_id', $cid)->where('status', '!=', 'void')->pluck('id')->flip();
    }

    /**
     * Sheet "Tagih Armada" / "Tagih Rental": satu baris per invoice kontrak
     * yang terbit dalam periode. ID = nomor invoice MY-TRUCK; Nilai Tagihan
     * = nominal invoice (bukan satuan × tarif ulang).
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    protected function billingCells(array $t, string $unitCol, int $cid, string $dariStr, string $untilStr, int $lastRow): array
    {
        $invoices = DB::table('invoices as i')
            ->join("{$t['table']} as c", 'c.id', '=', 'i.source_id')
            ->where('i.company_id', $cid)
            ->where('i.source_type', $t['source'])
            ->where('i.status', '!=', 'draft')
            ->where('i.invoice_date', '>=', $dariStr)->where('i.invoice_date', '<', $untilStr)
            ->orderBy('i.invoice_date')->orderByRaw('LENGTH(i.invoice_number)')->orderBy('i.invoice_number')
            ->select('i.*', 'c.contract_number')
            ->get();
        $this->assertCapacity("Tagih ({$t['source']})", $invoices->count(), $lastRow);

        $logs = DB::table($t['logTable'])
            ->whereIn('invoice_id', $invoices->pluck('id')->all())
            ->groupBy('invoice_id')
            ->selectRaw("invoice_id, SUM({$unitCol}) as n, MAX(log_date) as last_date")
            ->get()
            ->keyBy('invoice_id');

        $cells = [];
        $row   = self::FIRST_ROW;
        foreach ($invoices as $inv) {
            $l = $logs[$inv->id] ?? null;

            $cells += $this->dateParts('E', $row, $inv->invoice_date);
            $cells += $this->dateParts('O', $row, $l?->last_date ?? $inv->invoice_date);
            $cells += [
                "C{$row}" => $inv->invoice_number,          // timpa rumus TGA-/TGR-: nomor invoice MY-TRUCK
                "H{$row}" => $inv->contract_number,
                "S{$row}" => $l ? round((float) $l->n, 2) : null,
                "U{$row}" => $this->money($inv->amount),
                "V{$row}" => $inv->status === 'void' ? 'Batal' : 'Diajukan',
                "X{$row}" => "Status invoice MY-TRUCK: {$inv->status}",
            ];
            $row++;
        }

        return [$cells, $invoices->count()];
    }

    /** Kolom tanggal mulai/selesai + teks dropdown umum kontrak. */
    protected function contractCommon(object $c): array
    {
        return [
            'tipe'   => SopLookups::TIPE_KONTRAK[$c->tipe_kontrak ?? $c->tipe_rental ?? ''] ?? null,
            'status' => SopLookups::STATUS_KONTRAK[$c->status] ?? null,
            'start'  => $c->started_at,
            'end'    => $c->ended_at,
        ];
    }

    protected function logDate(object $l): Carbon
    {
        return Carbon::parse($l->log_date);
    }
}
