@extends('pdf._layout', ['reportTitle' => 'NERACA SALDO (TRIAL BALANCE)'])

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format($n, 0, ',', '.');
    $categories = [
        'aset'       => 'ASET',
        'kewajiban'  => 'KEWAJIBAN',
        'ekuitas'    => 'EKUITAS',
        'pendapatan' => 'PENDAPATAN',
        'beban'      => 'BEBAN',
        'penutup'    => 'AKUN PENUTUP',
    ];
@endphp

@section('content')
<table class="rpt">
    <thead>
        <tr>
            <th style="width: 12%;">Kode</th>
            <th>Nama Akun</th>
            <th class="text-right" style="width: 18%;">Debit (Rp)</th>
            <th class="text-right" style="width: 18%;">Kredit (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($categories as $key => $label)
            @if (isset($balances[$key]) && $balances[$key]->count() > 0)
                <tr class="section">
                    <td colspan="4">{{ $label }}</td>
                </tr>
                @foreach ($balances[$key] as $row)
                    <tr>
                        <td style="font-family: monospace;">{{ $row->code }}</td>
                        <td>{{ $row->name }}</td>
                        <td class="text-right">{{ $fmt($row->saldo_debit) }}</td>
                        <td class="text-right">{{ $fmt($row->saldo_kredit) }}</td>
                    </tr>
                @endforeach
            @endif
        @endforeach
        <tr class="grand">
            <td colspan="2">TOTAL</td>
            <td class="text-right">Rp {{ $fmt($totals['total_debit']) }}</td>
            <td class="text-right">Rp {{ $fmt($totals['total_kredit']) }}</td>
        </tr>
    </tbody>
</table>

<div class="balance-check {{ $totals['is_balanced'] ? 'balance-ok' : 'balance-bad' }}">
    {!! $totals['is_balanced']
        ? '✓ BALANCE — Total Debit = Total Kredit'
        : '✗ TIDAK BALANCE — Selisih: Rp ' . $fmt(abs($totals['total_debit'] - $totals['total_kredit'])) !!}
</div>
@endsection
