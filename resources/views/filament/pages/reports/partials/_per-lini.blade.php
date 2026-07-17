@php
    $fmt = fn ($n) => round($n, 2) == 0 ? '–' : 'Rp ' . number_format($n, 0, ',', '.');
    $columns = $businessUnits->toArray();
    if ($hasTanpaLini) {
        $columns[] = ['id' => $tanpaLiniId, 'code' => 'NONE', 'name' => 'Tanpa Lini', 'color' => '#94A3B8'];
    }

    $colorLabaRugi = fn ($n) => $n > 0
        ? 'var(--mt-accent-green)'
        : ($n < 0 ? 'var(--mt-accent-red)' : 'var(--mt-text-muted)');

    $bgTotalCol     = 'background: rgba(127, 127, 127, 0.08);';
    $bgTotalSubtle  = 'background: rgba(127, 127, 127, 0.05);';
    $bgTotalRevenue = 'background: rgba(59, 130, 246, 0.12);';
    $bgTotalCost    = 'background: rgba(245, 158, 11, 0.12);';
    $bgLabaRow      = 'background: rgba(34, 197, 94, 0.08);';
    $bgLabaTotal    = 'background: rgba(34, 197, 94, 0.18);';
@endphp

<div class="report-card">
    <div class="report-header">
        <div class="report-header-title">{{ $companyName }}</div>
        <div class="report-header-subtitle">
            Laporan Laba Rugi — Segmentasi per Lini Bisnis<br>
            <span style="font-weight: 400; font-size: 12px;">Periode: {{ $periodLabel }}</span>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="report-table" style="min-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 25%;">Uraian</th>
                    @foreach ($columns as $bu)
                        @php
                            $buArr = is_array($bu) ? $bu : $bu->toArray();
                            $color = $buArr['color'] ?? '#64748b';
                            $code = $buArr['code'] ?? '';
                        @endphp
                        <th class="text-right" style="color: {{ $color }}; border-bottom: 3px solid {{ $color }};">
                            <div style="font-weight: 800; font-size: 13px;">{{ $code }}</div>
                            <div style="font-size: 10px; font-weight: 500; opacity: 0.7;">{{ $buArr['name'] }}</div>
                        </th>
                    @endforeach
                    <th class="text-right" style="{{ $bgTotalCol }} font-weight: 800;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                {{-- PENDAPATAN --}}
                <tr class="report-row-category">
                    <td colspan="{{ count($columns) + 2 }}">I. PENDAPATAN USAHA</td>
                </tr>
                @foreach ($revenueRows as $row)
                    <tr>
                        <td>
                            <span style="font-family: monospace; color: var(--mt-text-muted); font-size: 11px;">{{ $row['code'] }}</span>
                            {{ $row['name'] }}
                        </td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                        @endforeach
                        <td class="text-right mono" style="{{ $bgTotalSubtle }} font-weight: 600;">{{ $fmt($row['total']) }}</td>
                    </tr>
                @endforeach
                <tr class="report-row-subtotal">
                    <td>Total Pendapatan</td>
                    @foreach ($columns as $bu)
                        @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                        <td class="text-right mono">{{ $fmt($revenuePerLini[$buId] ?? 0) }}</td>
                    @endforeach
                    <td class="text-right mono" style="{{ $bgTotalRevenue }} font-weight: 800;">{{ $fmt($totalRevenue) }}</td>
                </tr>

                {{-- HPP --}}
                <tr class="report-row-category">
                    <td colspan="{{ count($columns) + 2 }}">II. BEBAN POKOK PENDAPATAN (HPP)</td>
                </tr>
                @foreach ($hppRows as $row)
                    <tr>
                        <td>
                            <span style="font-family: monospace; color: var(--mt-text-muted); font-size: 11px;">{{ $row['code'] }}</span>
                            {{ $row['name'] }}
                        </td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                        @endforeach
                        <td class="text-right mono" style="{{ $bgTotalSubtle }} font-weight: 600;">{{ $fmt($row['total']) }}</td>
                    </tr>
                @endforeach
                <tr class="report-row-subtotal">
                    <td>Total HPP</td>
                    @foreach ($columns as $bu)
                        @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                        <td class="text-right mono">({{ $fmt($totalHppPerLini[$buId] ?? 0) }})</td>
                    @endforeach
                    <td class="text-right mono" style="{{ $bgTotalCost }} font-weight: 800;">({{ $fmt($totalHpp) }})</td>
                </tr>

                {{-- LABA KOTOR --}}
                <tr class="report-row-subtotal" style="{{ $bgLabaRow }}">
                    <td><strong>III. LABA KOTOR</strong></td>
                    @foreach ($columns as $bu)
                        @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                        <td class="text-right mono" style="color: {{ $colorLabaRugi($labaKotorPerLini[$buId] ?? 0) }}; font-weight: 700;">
                            {{ $fmt($labaKotorPerLini[$buId] ?? 0) }}
                        </td>
                    @endforeach
                    <td class="text-right mono" style="{{ $bgLabaTotal }} color: {{ $colorLabaRugi($totalLabaKotor) }}; font-weight: 800;">{{ $fmt($totalLabaKotor) }}</td>
                </tr>

                {{-- BEBAN OPERASIONAL --}}
                <tr class="report-row-category">
                    <td colspan="{{ count($columns) + 2 }}">IV. BEBAN OPERASIONAL</td>
                </tr>
                @foreach ($bebanOpRows as $row)
                    <tr>
                        <td>
                            <span style="font-family: monospace; color: var(--mt-text-muted); font-size: 11px;">{{ $row['code'] }}</span>
                            {{ $row['name'] }}
                        </td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                        @endforeach
                        <td class="text-right mono" style="{{ $bgTotalSubtle }} font-weight: 600;">{{ $fmt($row['total']) }}</td>
                    </tr>
                @endforeach
                <tr class="report-row-subtotal">
                    <td>Total Beban Operasional</td>
                    @foreach ($columns as $bu)
                        @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                        <td class="text-right mono">({{ $fmt($totalBebanOpPerLini[$buId] ?? 0) }})</td>
                    @endforeach
                    <td class="text-right mono" style="{{ $bgTotalCost }} font-weight: 800;">({{ $fmt($totalBebanOp) }})</td>
                </tr>

                {{-- LABA BERSIH --}}
                <tr class="report-row-subtotal" style="{{ $bgLabaRow }}">
                    <td><strong>V. LABA (RUGI) BERSIH</strong></td>
                    @foreach ($columns as $bu)
                        @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                        <td class="text-right mono" style="color: {{ $colorLabaRugi($labaBersihPerLini[$buId] ?? 0) }}; font-weight: 700;">
                            {{ $fmt($labaBersihPerLini[$buId] ?? 0) }}
                        </td>
                    @endforeach
                    <td class="text-right mono" style="{{ $bgLabaTotal }} color: {{ $colorLabaRugi($totalLabaBersih) }}; font-weight: 800;">{{ $fmt($totalLabaBersih) }}</td>
                </tr>

                {{-- MARGIN --}}
                <tr style="{{ $bgTotalCol }}">
                    <td style="font-style: italic; font-size: 12px;">Margin Laba (%)</td>
                    @foreach ($columns as $bu)
                        @php
                            $buId = is_array($bu) ? $bu['id'] : $bu->id;
                            $mrg = $marginPerLini[$buId];
                        @endphp
                        <td class="text-right mono" style="font-size: 12px; font-style: italic;">
                            {{ $mrg === null ? '—' : $mrg . '%' }}
                        </td>
                    @endforeach
                    <td class="text-right mono" style="font-size: 12px; font-weight: 700;">
                        {{ $totalMargin === null ? '—' : $totalMargin . '%' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($totalRevenue == 0)
        <div style="padding: 24px; text-align: center; color: var(--mt-text-muted);">
            <em>Belum ada transaksi pendapatan atau beban pada periode ini.</em>
        </div>
    @endif
</div>

@if ($totalRevenue > 0)
    @php
        $rankings = collect($revenuePerLini)
            ->filter(fn ($v, $k) => $k !== 0 && $v > 0)
            ->sortDesc();
        $topLini = $rankings->keys()->first();
        $topBu = $businessUnits->firstWhere('id', $topLini);
        $topVal = $rankings->first() ?? 0;
        $topPct = $totalRevenue > 0 ? round($topVal / $totalRevenue * 100, 1) : 0;

        $bestMargin = collect($marginPerLini)
            ->filter(fn ($v, $k) => $v !== null && $k !== 0 && ($revenuePerLini[$k] ?? 0) > 0)
            ->sortDesc();
        $bestMarginBuId = $bestMargin->keys()->first();
        $bestMarginBu = $businessUnits->firstWhere('id', $bestMarginBuId);
    @endphp
    <div style="margin-top: 16px; padding: 16px; background: rgba(59, 130, 246, 0.10); border-radius: 8px; border-left: 4px solid var(--mt-accent-blue); color: var(--mt-text);">
        <div style="font-weight: 700; color: var(--mt-accent-blue); margin-bottom: 8px;">Insight Cepat</div>
        <ul style="margin: 0; padding-left: 20px; color: var(--mt-text); font-size: 13px; line-height: 1.8;">
            @if ($topBu)
                <li><strong>{{ $topBu->name }}</strong> menyumbang <strong>{{ $topPct }}%</strong> pendapatan ({{ $fmt($topVal) }})</li>
            @endif
            @if ($bestMarginBu && $bestMargin->first() !== null)
                <li>Margin tertinggi: <strong>{{ $bestMarginBu->name }}</strong> dengan <strong>{{ $bestMargin->first() }}%</strong></li>
            @endif
            <li>Margin total perusahaan: <strong>{{ $totalMargin === null ? '—' : $totalMargin . '%' }}</strong></li>
        </ul>
    </div>
@endif
