@use('App\Services\Accounting\IncomeStatementByAssetService')

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : 'Rp ' . number_format((float) $n, 0, ',', '.');
    $labaColor = fn ($n) => $n > 0 ? 'var(--mt-accent-green, #16a34a)'
        : ($n < 0 ? 'var(--mt-accent-red, #dc2626)' : 'var(--mt-text-muted, #6b7280)');
@endphp

<div class="report-card">
    <div class="report-header">
        <div class="report-header-title">{{ $companyName }}</div>
        <div class="report-header-subtitle">
            Laporan Laba Rugi per Unit (Aset)<br>
            <span style="font-weight: 400; font-size: 12px;">
                Periode: {{ $periodLabel }}
                @if ($typeFilter ?? null)
                    · Filter jenis: {{ IncomeStatementByAssetService::typeLabel($typeFilter) }}
                @endif
            </span>
        </div>
    </div>

    @if (empty($assets))
        <div style="padding: 32px; text-align: center; color: var(--mt-text-muted, #6b7280);">
            Belum ada aktivitas jurnal dari aset di periode ini.
            @if ($typeFilter ?? null)
                Coba ganti filter jenis aset di atas.
            @else
                Aset akan muncul di sini setelah ada transaksi yang tag <code>asset_id</code> —
                misal: input log jam kerja (RENT), log ritase (ARMD), maintenance, atau
                depresiasi bulanan.
            @endif
        </div>
    @else
        <div style="overflow-x: auto;">
            <table class="report-table" style="min-width: 900px;">
                <thead>
                    <tr>
                        <th style="width: 22%;">Aset</th>
                        <th style="width: 12%;">Jenis</th>
                        <th class="text-right">Pendapatan</th>
                        <th class="text-right">HPP</th>
                        <th class="text-right">Laba Kotor</th>
                        <th class="text-right">Beban Op.</th>
                        <th class="text-right">Laba Bersih</th>
                        <th class="text-right" style="width: 8%;">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $row)
                        <tr>
                            <td>
                                <div style="font-weight: 600;">[{{ $row['asset_code'] }}]</div>
                                <div style="font-size: 12px; opacity: 0.75;">{{ $row['name'] }}</div>
                            </td>
                            <td>
                                <span style="font-size: 12px;">{{ IncomeStatementByAssetService::typeLabel($row['type']) }}</span>
                            </td>
                            <td class="text-right">{{ $fmt($row['revenue']) }}</td>
                            <td class="text-right">{{ $fmt($row['hpp']) }}</td>
                            <td class="text-right" style="color: {{ $labaColor($row['laba_kotor']) }}; font-weight: 600;">
                                {{ $fmt($row['laba_kotor']) }}
                            </td>
                            <td class="text-right">{{ $fmt($row['beban_op']) }}</td>
                            <td class="text-right" style="color: {{ $labaColor($row['laba_bersih']) }}; font-weight: 700;">
                                {{ $fmt($row['laba_bersih']) }}
                            </td>
                            <td class="text-right" style="color: {{ $labaColor($row['laba_bersih']) }};">
                                @if ($row['margin'] !== null)
                                    {{ number_format($row['margin'], 1, ',', '.') }}%
                                @else
                                    –
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    <tr style="background: rgba(127, 127, 127, 0.08); font-weight: 700; border-top: 2px solid rgba(127, 127, 127, 0.3);">
                        <td colspan="2">TOTAL</td>
                        <td class="text-right">{{ $fmt($totals['revenue']) }}</td>
                        <td class="text-right">{{ $fmt($totals['hpp']) }}</td>
                        <td class="text-right" style="color: {{ $labaColor($totals['laba_kotor']) }};">
                            {{ $fmt($totals['laba_kotor']) }}
                        </td>
                        <td class="text-right">{{ $fmt($totals['beban_op']) }}</td>
                        <td class="text-right" style="color: {{ $labaColor($totals['laba_bersih']) }};">
                            {{ $fmt($totals['laba_bersih']) }}
                        </td>
                        <td class="text-right" style="color: {{ $labaColor($totals['laba_bersih']) }};">
                            @if ($totals['margin'] !== null)
                                {{ number_format($totals['margin'], 1, ',', '.') }}%
                            @else
                                –
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px; padding: 12px; background: rgba(59, 130, 246, 0.08); border-radius: 6px; font-size: 12px; line-height: 1.6;">
            <strong>Cara Baca:</strong>
            Aset dengan <span style="color: var(--mt-accent-green, #16a34a); font-weight: 600;">Laba Bersih hijau</span>
            adalah unit yang memberikan keuntungan;
            <span style="color: var(--mt-accent-red, #dc2626); font-weight: 600;">yang merah</span> berarti biaya
            (penyusutan + maintenance + BBM + gaji) lebih besar dari pendapatan yang dihasilkan.
            Aset dengan revenue nol tapi cost besar biasanya = alat yang idle → pertimbangkan aktivasi atau divestasi.
        </div>
    @endif
</div>
