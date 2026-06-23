@extends('pdf._layout', ['reportTitle' => 'LAPORAN ARUS KAS (METODE LANGSUNG)'])

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format($n, 0, ',', '.');
@endphp

@section('content')
<table class="rpt">
    <tbody>
        <tr>
            <td>Saldo Kas & Bank Awal Periode</td>
            <td class="text-right">Rp {{ $fmt($saldoAwal) }}</td>
        </tr>

        <tr class="section">
            <td colspan="2">A. AKTIVITAS OPERASI</td>
        </tr>
        <tr>
            <td class="indent">Kas Masuk dari Operasi</td>
            <td class="text-right">{{ $fmt($kasMasukOperasi) }}</td>
        </tr>
        <tr>
            <td class="indent">Kas Keluar untuk Operasi</td>
            <td class="text-right">({{ $fmt($kasKeluarOperasi) }})</td>
        </tr>
        <tr class="subtotal">
            <td>Kas Bersih dari Aktivitas Operasi</td>
            <td class="text-right">{{ $fmt($kasMasukOperasi - $kasKeluarOperasi) }}</td>
        </tr>

        <tr class="section">
            <td colspan="2">B. AKTIVITAS INVESTASI</td>
        </tr>
        <tr>
            <td class="indent">Kas Masuk dari Investasi</td>
            <td class="text-right">{{ $fmt($kasMasukInvestasi) }}</td>
        </tr>
        <tr>
            <td class="indent">Kas Keluar untuk Investasi</td>
            <td class="text-right">({{ $fmt($kasKeluarInvestasi) }})</td>
        </tr>
        <tr class="subtotal">
            <td>Kas Bersih dari Aktivitas Investasi</td>
            <td class="text-right">{{ $fmt($kasMasukInvestasi - $kasKeluarInvestasi) }}</td>
        </tr>

        <tr class="section">
            <td colspan="2">C. AKTIVITAS PENDANAAN</td>
        </tr>
        <tr>
            <td class="indent">Kas Masuk dari Pendanaan</td>
            <td class="text-right">{{ $fmt($kasMasukPendanaan) }}</td>
        </tr>
        <tr>
            <td class="indent">Kas Keluar untuk Pendanaan</td>
            <td class="text-right">({{ $fmt($kasKeluarPendanaan) }})</td>
        </tr>
        <tr class="subtotal">
            <td>Kas Bersih dari Aktivitas Pendanaan</td>
            <td class="text-right">{{ $fmt($kasMasukPendanaan - $kasKeluarPendanaan) }}</td>
        </tr>

        <tr class="subtotal" style="background: #ECFDF5;">
            <td>Kenaikan (Penurunan) Bersih Kas</td>
            <td class="text-right">{{ $fmt($kenaikanBersih) }}</td>
        </tr>

        <tr class="grand">
            <td>SALDO KAS & BANK AKHIR PERIODE</td>
            <td class="text-right">Rp {{ $fmt($saldoAkhir) }}</td>
        </tr>
    </tbody>
</table>
@endsection
