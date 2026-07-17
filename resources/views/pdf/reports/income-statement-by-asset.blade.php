@extends('pdf._layout', ['reportTitle' => 'LAPORAN LABA RUGI PER UNIT (ASET)'])

@use('App\Services\Accounting\IncomeStatementByAssetService')

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format((float) $n, 0, ',', '.');
    $signed = fn ($n) => $n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.');
@endphp

@section('content')
    @if ($typeFilter ?? null)
        <div style="margin-bottom: 8px; font-size: 10px; font-style: italic;">
            Filter jenis aset: {{ IncomeStatementByAssetService::typeLabel($typeFilter) }}
        </div>
    @endif

    @if (empty($assets))
        <div style="padding: 24px; text-align: center; font-size: 11px; color: #666;">
            Tidak ada aktivitas jurnal per aset di periode ini.
        </div>
    @else
        <table class="rpt" style="font-size: 9px;">
            <thead>
                <tr>
                    <th style="width: 20%;">Aset</th>
                    <th style="width: 10%;">Jenis</th>
                    <th class="text-right" style="width: 12%;">Pendapatan</th>
                    <th class="text-right" style="width: 11%;">HPP</th>
                    <th class="text-right" style="width: 12%;">Laba Kotor</th>
                    <th class="text-right" style="width: 11%;">Beban Op.</th>
                    <th class="text-right" style="width: 14%;">Laba Bersih</th>
                    <th class="text-right" style="width: 10%;">Margin</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($assets as $row)
                    <tr>
                        <td>
                            <strong>[{{ $row['asset_code'] }}]</strong><br>
                            <span style="font-size: 8px; color: #555;">{{ $row['name'] }}</span>
                        </td>
                        <td style="font-size: 8px;">
                            {{ IncomeStatementByAssetService::typeLabel($row['type']) }}
                        </td>
                        <td class="text-right">{{ $fmt($row['revenue']) }}</td>
                        <td class="text-right">{{ $fmt($row['hpp']) }}</td>
                        <td class="text-right" style="font-weight: 600;">
                            {{ $signed($row['laba_kotor']) }}
                        </td>
                        <td class="text-right">{{ $fmt($row['beban_op']) }}</td>
                        <td class="text-right" style="font-weight: 700;">
                            {{ $signed($row['laba_bersih']) }}
                        </td>
                        <td class="text-right">
                            @if ($row['margin'] !== null)
                                {{ number_format($row['margin'], 1, ',', '.') }}%
                            @else
                                –
                            @endif
                        </td>
                    </tr>
                @endforeach

                {{-- Total row --}}
                <tr class="subtotal" style="background: #F3F4F6; font-weight: 700; border-top: 2px solid #333;">
                    <td colspan="2">TOTAL</td>
                    <td class="text-right">{{ $fmt($totals['revenue']) }}</td>
                    <td class="text-right">{{ $fmt($totals['hpp']) }}</td>
                    <td class="text-right">{{ $signed($totals['laba_kotor']) }}</td>
                    <td class="text-right">{{ $fmt($totals['beban_op']) }}</td>
                    <td class="text-right">{{ $signed($totals['laba_bersih']) }}</td>
                    <td class="text-right">
                        @if ($totals['margin'] !== null)
                            {{ number_format($totals['margin'], 1, ',', '.') }}%
                        @else
                            –
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 12px; padding: 8px; background: #F0F9FF; border: 1px solid #93C5FD; font-size: 9px; line-height: 1.5;">
            <strong>Catatan:</strong>
            Angka dalam kurung () menunjukkan rugi. Laba Bersih dihitung dari
            Pendapatan − HPP − Beban Operasional per aset. Nilai dalam kolom Beban Op
            termasuk penyusutan bulanan, maintenance, dan biaya operasional lain yang
            di-tag ke aset tersebut. Aset dengan Laba Bersih negatif menandakan biaya
            (penyusutan + maintenance) lebih besar dari pendapatan yang di-generate —
            evaluasi apakah alat perlu diaktifkan lebih sering atau divestasi.
        </div>
    @endif
@endsection
