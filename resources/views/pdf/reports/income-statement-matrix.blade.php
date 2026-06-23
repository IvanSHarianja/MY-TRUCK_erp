@extends('pdf._layout', ['reportTitle' => 'LAPORAN LABA RUGI — PER LINI BISNIS'])

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format($n, 0, ',', '.');
    $columns = $businessUnits->toArray();
    if ($hasTanpaLini) {
        $columns[] = ['id' => $tanpaLiniId, 'code' => 'NONE', 'name' => 'Tanpa Lini'];
    }
@endphp

@section('content')
<table class="rpt" style="font-size: 8.5px;">
    <thead>
        <tr>
            <th style="width: 24%;">Uraian</th>
            @foreach ($columns as $bu)
                @php
                    $buArr = is_array($bu) ? $bu : $bu->toArray();
                @endphp
                <th class="text-right">{{ $buArr['code'] }}</th>
            @endforeach
            <th class="text-right">TOTAL</th>
        </tr>
    </thead>
    <tbody>
        {{-- PENDAPATAN --}}
        <tr class="section">
            <td colspan="{{ count($columns) + 2 }}">I. PENDAPATAN</td>
        </tr>
        @foreach ($revenueRows as $row)
            <tr>
                <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                @foreach ($columns as $bu)
                    @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                    <td class="text-right">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                @endforeach
                <td class="text-right" style="font-weight: 600;">{{ $fmt($row['total']) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Pendapatan</td>
            @foreach ($columns as $bu)
                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                <td class="text-right">{{ $fmt($revenuePerLini[$buId] ?? 0) }}</td>
            @endforeach
            <td class="text-right">{{ $fmt($totalRevenue) }}</td>
        </tr>

        {{-- HPP --}}
        @if (count($hppRows) > 0)
        <tr class="section">
            <td colspan="{{ count($columns) + 2 }}">II. HPP</td>
        </tr>
        @foreach ($hppRows as $row)
            <tr>
                <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                @foreach ($columns as $bu)
                    @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                    <td class="text-right">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                @endforeach
                <td class="text-right" style="font-weight: 600;">{{ $fmt($row['total']) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total HPP</td>
            @foreach ($columns as $bu)
                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                <td class="text-right">({{ $fmt($totalHppPerLini[$buId] ?? 0) }})</td>
            @endforeach
            <td class="text-right">({{ $fmt($totalHpp) }})</td>
        </tr>
        @endif

        {{-- LABA KOTOR --}}
        <tr class="subtotal" style="background: #ECFDF5;">
            <td><strong>III. LABA KOTOR</strong></td>
            @foreach ($columns as $bu)
                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                <td class="text-right" style="font-weight: 700;">{{ $fmt($labaKotorPerLini[$buId] ?? 0) }}</td>
            @endforeach
            <td class="text-right" style="font-weight: 800;">{{ $fmt($totalLabaKotor) }}</td>
        </tr>

        {{-- BEBAN OP --}}
        @if (count($bebanOpRows) > 0)
        <tr class="section">
            <td colspan="{{ count($columns) + 2 }}">IV. BEBAN OPERASIONAL</td>
        </tr>
        @foreach ($bebanOpRows as $row)
            <tr>
                <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                @foreach ($columns as $bu)
                    @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                    <td class="text-right">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                @endforeach
                <td class="text-right" style="font-weight: 600;">{{ $fmt($row['total']) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Total Beban Operasional</td>
            @foreach ($columns as $bu)
                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                <td class="text-right">({{ $fmt($totalBebanOpPerLini[$buId] ?? 0) }})</td>
            @endforeach
            <td class="text-right">({{ $fmt($totalBebanOp) }})</td>
        </tr>
        @endif

        {{-- LABA BERSIH --}}
        <tr class="grand">
            <td>V. LABA (RUGI) BERSIH</td>
            @foreach ($columns as $bu)
                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                <td class="text-right">{{ $fmt($labaBersihPerLini[$buId] ?? 0) }}</td>
            @endforeach
            <td class="text-right">{{ $fmt($totalLabaBersih) }}</td>
        </tr>
        <tr>
            <td style="font-style: italic; color: #555;">Margin Laba (%)</td>
            @foreach ($columns as $bu)
                @php
                    $buId = is_array($bu) ? $bu['id'] : $bu->id;
                    $m = $marginPerLini[$buId] ?? null;
                @endphp
                <td class="text-right" style="font-style: italic;">{{ $m === null ? '—' : $m . '%' }}</td>
            @endforeach
            <td class="text-right" style="font-style: italic; font-weight: 600;">{{ $totalMargin === null ? '—' : $totalMargin . '%' }}</td>
        </tr>
    </tbody>
</table>
@endsection
