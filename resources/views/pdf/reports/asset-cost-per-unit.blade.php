@extends('pdf._layout', ['reportTitle' => 'BIAYA OPERASIONAL PER UNIT'])

@use('App\Services\Accounting\AssetCostPerUnitService')

@php
    $fmt = fn ($n) => ($n === null || round((float) $n, 2) == 0) ? '–' : number_format((float) $n, 0, ',', '.');
    $signed = fn ($n) => $n === null
        ? '–'
        : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.'));
@endphp

@section('content')
    <div style="margin-bottom: 8px; font-size: 10px;">
        Periode: {{ $period_label }}
        @if ($filters['type'] ?? null)
            · Jenis: {{ AssetCostPerUnitService::typeLabel($filters['type']) }}
        @endif
        @if ($filters['only_losing'] ?? false)
            · <strong style="color: #b91c1c;">Filter: hanya rugi</strong>
        @endif
    </div>

    @if (($losing_count ?? 0) > 0 && ! ($filters['only_losing'] ?? false))
        <div style="margin-bottom: 8px; padding: 6px 10px; background: #FEE2E2; border-left: 3px solid #b91c1c; font-size: 9px;">
            <strong>⚠ {{ $losing_count }} aset rugi per unit</strong> di periode ini.
        </div>
    @endif

    @if (empty($rows))
        <div style="padding: 24px; text-align: center; font-size: 11px; color: #666;">
            @if ($filters['only_losing'] ?? false)
                Tidak ada aset rugi di periode ini.
            @else
                Belum ada aktivitas jurnal per aset di periode ini.
            @endif
        </div>
    @else
        <table class="rpt" style="font-size: 8.5px;">
            <thead>
                <tr>
                    <th style="width: 14%;">Aset</th>
                    <th style="width: 8%;">Jenis</th>
                    <th class="text-right" style="width: 8%;">Usage</th>
                    <th class="text-right" style="width: 10%;">Revenue</th>
                    <th class="text-right" style="width: 10%;">Cost Total</th>
                    <th class="text-right" style="width: 10%;">Net</th>
                    <th class="text-right" style="width: 10%;">Cost / Unit</th>
                    <th class="text-right" style="width: 10%;">Rev / Unit</th>
                    <th class="text-right" style="width: 10%;">Margin / Unit</th>
                    <th class="text-right" style="width: 6%;">Margin %</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr @if ($row['is_losing']) style="background: #FEE2E2;" @endif>
                        <td>
                            @if ($row['is_losing']) <span style="color:#b91c1c;">⚠</span> @endif
                            <strong>[{{ $row['asset_code'] }}]</strong><br>
                            <span style="font-size: 8px; color: #555;">{{ $row['name'] }}</span>
                        </td>
                        <td style="font-size: 8px;">
                            {{ AssetCostPerUnitService::typeLabel($row['type']) }}
                        </td>
                        <td class="text-right" style="font-size: 8px;">
                            @if ($row['channel'])
                                {{ number_format((float) $row['usage'], (float) $row['usage'] == (int) $row['usage'] ? 0 : 2, ',', '.') }}
                                <span style="color: #666;">{{ AssetCostPerUnitService::channelLabel($row['channel']) }}</span>
                            @else
                                <span style="color: #999;">–</span>
                            @endif
                        </td>
                        <td class="text-right">{{ $fmt($row['revenue']) }}</td>
                        <td class="text-right">{{ $fmt($row['cost_total']) }}</td>
                        <td class="text-right" style="font-weight: 600; color: {{ $row['net'] < 0 ? '#b91c1c' : '#166534' }};">
                            {{ $signed($row['net']) }}
                        </td>
                        <td class="text-right">{{ $fmt($row['cost_per_unit']) }}</td>
                        <td class="text-right">{{ $fmt($row['revenue_per_unit']) }}</td>
                        <td class="text-right" style="font-weight: 700; color: {{ ($row['margin_per_unit'] ?? 0) < 0 ? '#b91c1c' : '#166534' }};">
                            {{ $signed($row['margin_per_unit']) }}
                        </td>
                        <td class="text-right">
                            @if ($row['margin_pct'] !== null)
                                {{ number_format($row['margin_pct'], 1, ',', '.') }}%
                            @else
                                –
                            @endif
                        </td>
                    </tr>
                @endforeach

                <tr class="subtotal" style="background: #F3F4F6; font-weight: 700; border-top: 2px solid #333;">
                    <td colspan="3">TOTAL</td>
                    <td class="text-right">{{ $fmt($totals['revenue']) }}</td>
                    <td class="text-right">{{ $fmt($totals['cost']) }}</td>
                    <td class="text-right" style="color: {{ $totals['net'] < 0 ? '#b91c1c' : '#166534' }};">
                        {{ $signed($totals['net']) }}
                    </td>
                    <td colspan="4"></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 10px; padding: 8px; background: #F0F9FF; border: 1px solid #93C5FD; font-size: 8px; line-height: 1.5;">
            <strong>Cara Baca.</strong>
            Usage = jam kerja (RentalLog) atau rit (RitLog) berdasar tipe/method aset.
            Cost Total = jumlah semua beban yang tag asset_id (BBM, gaji, premi, penyusutan, maintenance).
            Baris merah muda dengan ⚠ = cost per unit &gt; revenue per unit → rugi struktural.
            Aset tanpa Usage = idle di periode ini.
        </div>
    @endif
@endsection
