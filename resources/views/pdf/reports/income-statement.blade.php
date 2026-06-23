@extends('pdf._layout', ['reportTitle' => 'LAPORAN LABA RUGI (INCOME STATEMENT)'])

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format($n, 0, ',', '.');
@endphp

@section('content')
<table class="rpt">
    <tbody>
        {{-- PENDAPATAN --}}
        <tr class="section">
            <td colspan="2">I. PENDAPATAN USAHA</td>
        </tr>
        @foreach ($pendapatan as $row)
            <tr>
                <td class="indent">{{ $row->code }} — {{ $row->name }}</td>
                <td class="text-right">{{ $fmt($row->saldo_kredit) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Pendapatan</td>
            <td class="text-right">Rp {{ $fmt($totalPendapatan) }}</td>
        </tr>

        {{-- HPP --}}
        @if ($hpp->count() > 0)
        <tr class="section">
            <td colspan="2">II. BEBAN POKOK PENDAPATAN (HPP)</td>
        </tr>
        @foreach ($hpp as $row)
            <tr>
                <td class="indent">{{ $row->code }} — {{ $row->name }}</td>
                <td class="text-right">{{ $fmt($row->saldo_debit) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total HPP</td>
            <td class="text-right">(Rp {{ $fmt($totalHpp) }})</td>
        </tr>
        @endif

        {{-- LABA KOTOR --}}
        <tr class="subtotal" style="background: #ECFDF5;">
            <td>III. LABA KOTOR</td>
            <td class="text-right">Rp {{ $fmt($labaKotor) }}</td>
        </tr>

        {{-- BEBAN OPERASIONAL --}}
        @if ($bebanOp->count() > 0)
        <tr class="section">
            <td colspan="2">IV. BEBAN OPERASIONAL</td>
        </tr>
        @foreach ($bebanOp as $row)
            <tr>
                <td class="indent">{{ $row->code }} — {{ $row->name }}</td>
                <td class="text-right">{{ $fmt($row->saldo_debit) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Beban Operasional</td>
            <td class="text-right">(Rp {{ $fmt($totalBebanOp) }})</td>
        </tr>
        @endif

        {{-- LABA BERSIH --}}
        <tr class="grand">
            <td>V. LABA (RUGI) BERSIH</td>
            <td class="text-right">Rp {{ $fmt($labaBersih) }}</td>
        </tr>
        <tr>
            <td style="font-style: italic; color: #555;">Margin Laba Bersih</td>
            <td class="text-right" style="font-style: italic; color: #555;">{{ $marginLaba }}%</td>
        </tr>
    </tbody>
</table>
@endsection
