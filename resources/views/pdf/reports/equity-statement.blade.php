@extends('pdf._layout', ['reportTitle' => 'LAPORAN PERUBAHAN EKUITAS'])

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format($n, 0, ',', '.');
@endphp

@section('content')
<table class="rpt">
    <tbody>
        <tr>
            <td>Modal Pemilik (Saldo Awal Periode)</td>
            <td class="text-right">Rp {{ $fmt($modalPemilik) }}</td>
        </tr>
        <tr>
            <td>Laba Ditahan (Retained Earnings)</td>
            <td class="text-right">Rp {{ $fmt($labaDitahan) }}</td>
        </tr>
        <tr>
            <td>Laba (Rugi) Bersih Tahun Berjalan</td>
            <td class="text-right">Rp {{ $fmt($labaBerjalan) }}</td>
        </tr>
        <tr>
            <td>(-) Prive / Pengambilan Modal</td>
            <td class="text-right">(Rp {{ $fmt($prive) }})</td>
        </tr>
        <tr class="grand">
            <td>TOTAL EKUITAS AKHIR PERIODE</td>
            <td class="text-right">Rp {{ $fmt($totalEkuitas) }}</td>
        </tr>
    </tbody>
</table>
@endsection
