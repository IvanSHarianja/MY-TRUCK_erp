@extends('pdf._layout', ['reportTitle' => 'LAPORAN ARUS KAS'])

@php
    // Format IDR: 100.000.000, zero → em-dash. Excel: kosong kalau 0.
    $fmt = fn ($n) => (float) $n == 0.0 ? '' : number_format($n, 0, ',', '.');
    // Palet warna mirror Excel LAK ALBER & EXP.
    $cKuning = '#FFFF00';   // subtotal per section
    $cHijau  = '#00FF00';   // grand total (penerimaan/pengeluaran)
    $cCyan   = '#00FFFF';   // saldo akhir
    $cMerah  = '#FF0000';   // angka pengeluaran per baris
@endphp

@section('content')
<style>
    /* Override style _layout untuk LAK — kolom padat & ada warna khas Excel. */
    table.lak {
        width: 100%;
        border-collapse: collapse;
        font-size: 9.5px;
        margin-top: 8px;
    }
    table.lak td {
        border: 1px solid #333;
        padding: 4px 6px;
        vertical-align: middle;
    }
    table.lak td.maping    { width: 22%; text-transform: uppercase; }
    table.lak td.desc      { width: 40%; }
    table.lak td.detail    { width: 14%; text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
    table.lak td.subtotal  { width: 14%; text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
    table.lak td.center    { text-align: center; }
    table.lak td.bold      { font-weight: bold; }
    table.lak td.red       { color: {{ $cMerah }}; }

    /* Section header (Penerimaan :, Pengeluaran Expedisi :) */
    table.lak tr.section td {
        font-weight: bold;
        background: #F3F4F6;
    }
    /* Subtotal per section → kuning */
    table.lak tr.subtot td {
        font-weight: bold;
        background: {{ $cKuning }};
    }
    /* Grand total (Total Penerimaan / Total Pengeluaran) → hijau */
    table.lak tr.grand td {
        font-weight: bold;
        background: {{ $cHijau }};
        text-align: center;
    }
    table.lak tr.grand td.subtotal { text-align: right; }
    /* Saldo akhir → cyan */
    table.lak tr.saldo td {
        font-weight: bold;
        background: {{ $cCyan }};
        font-size: 11px;
    }
</style>

<table class="lak">
    <thead>
        <tr class="section">
            <td class="maping center">MAPING</td>
            <td class="desc center">TRANSAKSI</td>
            <td class="detail center">DETAIL</td>
            <td class="subtotal center">SUBTOTAL</td>
        </tr>
    </thead>
    <tbody>
        {{-- SALDO AWAL --}}
        <tr>
            <td class="maping bold">SALDO AWAL</td>
            <td class="desc bold">Saldo Awal Kas & Bank</td>
            <td class="detail"></td>
            <td class="subtotal bold">{{ $fmt($saldoAwal) }}</td>
        </tr>

        {{-- PENERIMAAN --}}
        <tr class="section">
            <td colspan="4">Penerimaan :</td>
        </tr>
        @foreach ($penerimaan as $row)
            <tr>
                <td class="maping">{{ $row['label'] }}</td>
                <td class="desc">{{ $row['description'] }}</td>
                <td class="detail">{{ $fmt($row['amount']) }}</td>
                <td class="subtotal"></td>
            </tr>
        @endforeach
        <tr class="grand">
            <td colspan="2">Total Penerimaan</td>
            <td class="detail"></td>
            <td class="subtotal">{{ $fmt($totalPenerimaan) }}</td>
        </tr>

        {{-- PENGELUARAN per section — angka merah, subtotal kuning --}}
        @foreach ($pengeluaran as $section)
            <tr class="section">
                <td colspan="4">{{ $section['title'] }} :</td>
            </tr>
            @foreach ($section['items'] as $row)
                <tr>
                    <td class="maping">{{ $row['label'] }}</td>
                    <td class="desc">{{ $row['description'] }}</td>
                    <td class="detail red">{{ $fmt($row['amount']) }}</td>
                    <td class="subtotal"></td>
                </tr>
            @endforeach
            <tr class="subtot">
                <td colspan="2">Total {{ $section['title'] }}</td>
                <td class="detail"></td>
                <td class="subtotal">{{ $fmt($section['total']) }}</td>
            </tr>
        @endforeach

        {{-- Total Pengeluaran → hijau --}}
        <tr class="grand">
            <td colspan="2">Total Pengeluaran</td>
            <td class="detail"></td>
            <td class="subtotal">{{ $fmt($totalPengeluaran) }}</td>
        </tr>

        {{-- Baris kosong pemisah --}}
        <tr><td colspan="4" style="border:none; height:6px;"></td></tr>

        {{-- SALDO AKHIR → cyan --}}
        <tr class="saldo">
            <td colspan="3" class="center">SALDO AKHIR KAS & BANK</td>
            <td class="subtotal">Rp {{ $fmt($saldoAkhir) }}</td>
        </tr>
    </tbody>
</table>

<div style="margin-top:14px; font-size:8px; color:#666;">
    Format ini mirror layout Excel LAK operasional. Untuk format standar PSAK
    (Operasi/Investasi/Pendanaan), lihat laporan "Arus Kas (Metode Langsung)".
</div>
@endsection
