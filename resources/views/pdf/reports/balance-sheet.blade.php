@extends('pdf._layout', ['reportTitle' => 'NERACA (BALANCE SHEET)'])

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format($n, 0, ',', '.');
@endphp

@section('content')
<table class="rpt" style="width: 49%; float: left;">
    <thead>
        <tr><th colspan="2" style="text-align: center;">ASET</th></tr>
    </thead>
    <tbody>
        <tr class="section">
            <td colspan="2">ASET LANCAR</td>
        </tr>
        @foreach ($asetLancar as $row)
            <tr>
                <td class="indent">{{ $row->code }} {{ $row->name }}</td>
                <td class="text-right">{{ $fmt($row->saldo_debit) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Aset Lancar</td>
            <td class="text-right">{{ $fmt($totalAsetLancar) }}</td>
        </tr>

        <tr class="section">
            <td colspan="2">ASET TETAP</td>
        </tr>
        @foreach ($asetTetap as $row)
            <tr>
                <td class="indent">{{ $row->code }} {{ $row->name }}</td>
                <td class="text-right">{{ $row->normal_balance === 'kredit' ? '(' . $fmt($row->saldo_kredit) . ')' : $fmt($row->saldo_debit) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Aset Tetap (Nilai Buku)</td>
            <td class="text-right">{{ $fmt($totalAsetTetap) }}</td>
        </tr>

        <tr class="grand">
            <td>TOTAL ASET</td>
            <td class="text-right">Rp {{ $fmt($totalAset) }}</td>
        </tr>
    </tbody>
</table>

<table class="rpt" style="width: 49%; float: right;">
    <thead>
        <tr><th colspan="2" style="text-align: center;">PASIVA</th></tr>
    </thead>
    <tbody>
        <tr class="section">
            <td colspan="2">KEWAJIBAN LANCAR</td>
        </tr>
        @foreach ($kwjbLancar as $row)
            <tr>
                <td class="indent">{{ $row->code }} {{ $row->name }}</td>
                <td class="text-right">{{ $fmt($row->saldo_kredit) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Kewajiban Lancar</td>
            <td class="text-right">{{ $fmt($totalKwjbLancar) }}</td>
        </tr>

        @if ($kwjbPanjang->count() > 0)
        <tr class="section">
            <td colspan="2">KEWAJIBAN JANGKA PANJANG</td>
        </tr>
        @foreach ($kwjbPanjang as $row)
            <tr>
                <td class="indent">{{ $row->code }} {{ $row->name }}</td>
                <td class="text-right">{{ $fmt($row->saldo_kredit) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Kewajiban Jangka Panjang</td>
            <td class="text-right">{{ $fmt($totalKwjbPanjang) }}</td>
        </tr>
        @endif

        <tr class="section">
            <td colspan="2">EKUITAS</td>
        </tr>
        @foreach ($ekuitas as $row)
            <tr>
                <td class="indent">{{ $row->code }} {{ $row->name }}</td>
                <td class="text-right">{{ $row->normal_balance === 'debit' ? '(' . $fmt($row->saldo_debit) . ')' : $fmt($row->saldo_kredit) }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="indent">Laba Berjalan</td>
            <td class="text-right">{{ $fmt($labaBerjalan) }}</td>
        </tr>
        <tr class="subtotal">
            <td>Total Ekuitas</td>
            <td class="text-right">{{ $fmt($totalEkuitas) }}</td>
        </tr>

        <tr class="grand">
            <td>TOTAL PASIVA</td>
            <td class="text-right">Rp {{ $fmt($totalPasiva) }}</td>
        </tr>
    </tbody>
</table>

<div style="clear: both;"></div>

<div class="balance-check {{ $isBalanced ? 'balance-ok' : 'balance-bad' }}">
    {!! $isBalanced
        ? '✓ SEIMBANG — Total Aset = Total Pasiva'
        : '✗ TIDAK SEIMBANG — Selisih: Rp ' . $fmt(abs($selisih)) !!}
</div>
@endsection
