<?php

namespace App\Services\Accounting;

use App\Models\MaterialSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Margin per penjualan material = pendapatan − HPP − biaya pengiriman
 * (masukan atasan Ivan 2026-11-18: "harga penjualan dikurangi pengeluaran
 * ketemu marginnya").
 *
 * Penjualan yang invoice-nya di-void tidak dihitung (jurnalnya sudah dibalik).
 */
class MaterialMarginService
{
    /**
     * @return array{rows: Collection, totals: array{pendapatan: float, hpp: float, kirim: float, margin: float, pct: float}}
     */
    public function report(int $companyId, Carbon $dari, Carbon $sampai, ?int $clientId = null, ?int $materialId = null): array
    {
        $sales = MaterialSale::withoutGlobalScopes()
            ->with(['client', 'material', 'asset', 'invoice'])
            ->where('company_id', $companyId)
            ->where('sale_date', '>=', $dari->toDateString())
            ->where('sale_date', '<', $sampai->copy()->addDay()->toDateString())
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($materialId, fn ($q) => $q->where('material_id', $materialId))
            ->orderBy('sale_date')->orderBy('sale_number')
            ->get()
            ->reject(fn (MaterialSale $s) => $s->metode === 'invoice' && ($s->invoice_id === null || $s->invoice?->status === 'void'));

        $rows = $sales->map(function (MaterialSale $s) {
            $pendapatan = (float) $s->total;
            $margin = $s->margin();

            return [
                'sale'       => $s,
                'pendapatan' => $pendapatan,
                'hpp'        => (float) $s->hpp_total,
                'kirim'      => (float) $s->biaya_kirim_total,
                'margin'     => $margin,
                'pct'        => $pendapatan > 0 ? round($margin / $pendapatan * 100, 1) : 0.0,
            ];
        })->values();

        $totals = [
            'pendapatan' => (float) $rows->sum('pendapatan'),
            'hpp'        => (float) $rows->sum('hpp'),
            'kirim'      => (float) $rows->sum('kirim'),
            'margin'     => (float) $rows->sum('margin'),
        ];
        $totals['pct'] = $totals['pendapatan'] > 0 ? round($totals['margin'] / $totals['pendapatan'] * 100, 1) : 0.0;

        return ['rows' => $rows, 'totals' => $totals];
    }
}
