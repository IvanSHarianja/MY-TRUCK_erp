<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $fmt = fn ($n) => round($n, 2) == 0 ? '–' : 'Rp ' . number_format($n, 0, ',', '.');
        $columns = $businessUnits->toArray();
        if ($hasTanpaLini) {
            $columns[] = ['id' => $tanpaLiniId, 'code' => 'NONE', 'name' => 'Tanpa Lini', 'color' => '#94A3B8'];
        }

        // Helper untuk warna teks laba/rugi
        $colorLabaRugi = fn ($n) => $n > 0 ? '#16a34a' : ($n < 0 ? '#dc2626' : '#64748b');
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
                        <th class="text-right" style="background: #f1f5f9; font-weight: 800;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- ============ PENDAPATAN ============ --}}
                    <tr class="report-row-category">
                        <td colspan="{{ count($columns) + 2 }}">I. PENDAPATAN USAHA</td>
                    </tr>
                    @foreach ($revenueRows as $row)
                        <tr>
                            <td>
                                <span style="font-family: monospace; color: #64748b; font-size: 11px;">{{ $row['code'] }}</span>
                                {{ $row['name'] }}
                            </td>
                            @foreach ($columns as $bu)
                                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                                <td class="text-right mono">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                            @endforeach
                            <td class="text-right mono" style="background: #f8fafc; font-weight: 600;">{{ $fmt($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="report-row-subtotal">
                        <td>Total Pendapatan</td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono">{{ $fmt($revenuePerLini[$buId] ?? 0) }}</td>
                        @endforeach
                        <td class="text-right mono" style="background: #e0f2fe; font-weight: 800;">{{ $fmt($totalRevenue) }}</td>
                    </tr>

                    {{-- ============ HPP ============ --}}
                    <tr class="report-row-category">
                        <td colspan="{{ count($columns) + 2 }}">II. BEBAN POKOK PENDAPATAN (HPP)</td>
                    </tr>
                    @foreach ($hppRows as $row)
                        <tr>
                            <td>
                                <span style="font-family: monospace; color: #64748b; font-size: 11px;">{{ $row['code'] }}</span>
                                {{ $row['name'] }}
                            </td>
                            @foreach ($columns as $bu)
                                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                                <td class="text-right mono">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                            @endforeach
                            <td class="text-right mono" style="background: #f8fafc; font-weight: 600;">{{ $fmt($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="report-row-subtotal">
                        <td>Total HPP</td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono">({{ $fmt($totalHppPerLini[$buId] ?? 0) }})</td>
                        @endforeach
                        <td class="text-right mono" style="background: #fef3c7; font-weight: 800;">({{ $fmt($totalHpp) }})</td>
                    </tr>

                    {{-- ============ LABA KOTOR ============ --}}
                    <tr class="report-row-subtotal" style="background: #ecfdf5;">
                        <td><strong>III. LABA KOTOR</strong></td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono" style="color: {{ $colorLabaRugi($labaKotorPerLini[$buId] ?? 0) }}; font-weight: 700;">
                                {{ $fmt($labaKotorPerLini[$buId] ?? 0) }}
                            </td>
                        @endforeach
                        <td class="text-right mono" style="background: #d1fae5; color: {{ $colorLabaRugi($totalLabaKotor) }}; font-weight: 800;">{{ $fmt($totalLabaKotor) }}</td>
                    </tr>

                    {{-- ============ BEBAN OPERASIONAL ============ --}}
                    <tr class="report-row-category">
                        <td colspan="{{ count($columns) + 2 }}">IV. BEBAN OPERASIONAL</td>
                    </tr>
                    @foreach ($bebanOpRows as $row)
                        <tr>
                            <td>
                                <span style="font-family: monospace; color: #64748b; font-size: 11px;">{{ $row['code'] }}</span>
                                {{ $row['name'] }}
                            </td>
                            @foreach ($columns as $bu)
                                @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                                <td class="text-right mono">{{ $fmt($row['perLini'][$buId] ?? 0) }}</td>
                            @endforeach
                            <td class="text-right mono" style="background: #f8fafc; font-weight: 600;">{{ $fmt($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="report-row-subtotal">
                        <td>Total Beban Operasional</td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono">({{ $fmt($totalBebanOpPerLini[$buId] ?? 0) }})</td>
                        @endforeach
                        <td class="text-right mono" style="background: #fef3c7; font-weight: 800;">({{ $fmt($totalBebanOp) }})</td>
                    </tr>

                    {{-- ============ LABA BERSIH ============ --}}
                    <tr class="report-row-subtotal" style="background: #ecfdf5;">
                        <td><strong>V. LABA (RUGI) BERSIH</strong></td>
                        @foreach ($columns as $bu)
                            @php $buId = is_array($bu) ? $bu['id'] : $bu->id; @endphp
                            <td class="text-right mono" style="color: {{ $colorLabaRugi($labaBersihPerLini[$buId] ?? 0) }}; font-weight: 700;">
                                {{ $fmt($labaBersihPerLini[$buId] ?? 0) }}
                            </td>
                        @endforeach
                        <td class="text-right mono" style="background: #d1fae5; color: {{ $colorLabaRugi($totalLabaBersih) }}; font-weight: 800;">{{ $fmt($totalLabaBersih) }}</td>
                    </tr>

                    {{-- ============ MARGIN ============ --}}
                    <tr style="background: #f1f5f9;">
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
            <div style="padding: 24px; text-align: center; color: #64748b;">
                <em>Belum ada transaksi pendapatan atau beban pada periode ini.</em>
            </div>
        @endif
    </div>

    {{-- Insight Box --}}
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
        <div style="margin-top: 16px; padding: 16px; background: linear-gradient(135deg, #eff6ff, #dbeafe); border-radius: 8px; border-left: 4px solid #2563eb;">
            <div style="font-weight: 700; color: #1e40af; margin-bottom: 8px;">📊 Insight Cepat</div>
            <ul style="margin: 0; padding-left: 20px; color: #1e293b; font-size: 13px; line-height: 1.8;">
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
</x-filament-panels::page>
