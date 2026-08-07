@use('App\Services\Accounting\AssetCostPerUnitService')

<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $fmt = fn ($n) => ($n === null || round((float) $n, 2) == 0)
            ? '–'
            : 'Rp ' . number_format((float) $n, 0, ',', '.');
        $signed = fn ($n) => $n === null
            ? '–'
            : ($n < 0
                ? '(Rp ' . number_format(abs($n), 0, ',', '.') . ')'
                : 'Rp ' . number_format($n, 0, ',', '.'));
        $rows = $rows ?? [];
        $totals = $totals ?? ['revenue' => 0, 'cost' => 0, 'net' => 0, 'jam' => 0, 'rit' => 0];
    @endphp

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">
                Biaya Operasional per Unit<br>
                <span style="font-weight: 400; font-size: 12px;">
                    Periode: {{ $period_label }}
                    @if ($typeFilter)
                        · Jenis: {{ AssetCostPerUnitService::typeLabel($typeFilter) }}
                    @endif
                    @if ($onlyLosing)
                        · <span style="color: var(--mt-accent-red, #dc2626); font-weight: 600;">Filter: hanya rugi</span>
                    @endif
                </span>
            </div>
        </div>

        @if (($losing_count ?? 0) > 0 && ! $onlyLosing)
            <div style="margin-bottom: 12px; padding: 10px 14px; background: rgba(220, 38, 38, 0.08); border-left: 3px solid var(--mt-accent-red, #dc2626); border-radius: 4px; font-size: 12px;">
                <strong style="color: var(--mt-accent-red, #dc2626);">⚠ {{ $losing_count }} aset rugi per unit</strong>
                — cost per satuan lebih tinggi dari revenue per satuan. Filter <em>"Hanya yang rugi"</em> di atas untuk fokus.
            </div>
        @endif

        @if (empty($rows))
            <div style="padding: 32px; text-align: center; color: var(--mt-text-muted, #6b7280);">
                @if ($onlyLosing)
                    Tidak ada aset rugi di periode ini. 🎉
                @else
                    Belum ada aktivitas jurnal untuk aset di periode ini.
                @endif
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="report-table" style="min-width: 1200px;">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Aset</th>
                            <th style="width: 8%;">Jenis</th>
                            <th class="text-right" style="width: 8%;">Usage</th>
                            <th class="text-right" style="width: 11%;">Revenue</th>
                            <th class="text-right" style="width: 11%;">Cost Total</th>
                            <th class="text-right" style="width: 11%;">Net</th>
                            <th class="text-right" style="width: 10%;">Cost / Unit</th>
                            <th class="text-right" style="width: 10%;">Rev / Unit</th>
                            <th class="text-right" style="width: 10%;">Margin / Unit</th>
                            <th class="text-right" style="width: 6%;">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr @if ($row['is_losing']) style="background: rgba(220, 38, 38, 0.06);" @endif>
                                <td>
                                    <div style="font-weight: 600;">
                                        @if ($row['is_losing'])
                                            <span style="color: var(--mt-accent-red, #dc2626);">⚠</span>
                                        @endif
                                        [{{ $row['asset_code'] }}]
                                    </div>
                                    <div style="font-size: 12px; opacity: 0.75;">{{ $row['name'] }}</div>
                                </td>
                                <td>
                                    <span style="font-size: 12px;">{{ AssetCostPerUnitService::typeLabel($row['type']) }}</span>
                                </td>
                                <td class="text-right mono" style="font-size: 12px;">
                                    @if ($row['channel'])
                                        {{ number_format((float) $row['usage'], (float) $row['usage'] == (int) $row['usage'] ? 0 : 2, ',', '.') }}
                                        <span class="muted" style="font-size: 11px;">{{ AssetCostPerUnitService::channelLabel($row['channel']) }}</span>
                                    @else
                                        <span class="muted">–</span>
                                    @endif
                                </td>
                                <td class="text-right mono">{{ $fmt($row['revenue']) }}</td>
                                <td class="text-right mono">{{ $fmt($row['cost_total']) }}</td>
                                <td class="text-right mono" style="font-weight: 600; color: {{ $row['net'] < 0 ? 'var(--mt-accent-red, #dc2626)' : 'var(--mt-accent-green, #16a34a)' }};">
                                    {{ $signed($row['net']) }}
                                </td>
                                <td class="text-right mono">{{ $fmt($row['cost_per_unit']) }}</td>
                                <td class="text-right mono">{{ $fmt($row['revenue_per_unit']) }}</td>
                                <td class="text-right mono" style="font-weight: 700; color: {{ ($row['margin_per_unit'] ?? 0) < 0 ? 'var(--mt-accent-red, #dc2626)' : 'var(--mt-accent-green, #16a34a)' }};">
                                    {{ $signed($row['margin_per_unit']) }}
                                </td>
                                <td class="text-right mono" style="font-size: 12px;">
                                    @if ($row['margin_pct'] !== null)
                                        {{ number_format($row['margin_pct'], 1, ',', '.') }}%
                                    @else
                                        <span class="muted">–</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        <tr style="background: rgba(127, 127, 127, 0.10); font-weight: 700; border-top: 2px solid rgba(127, 127, 127, 0.3);">
                            <td colspan="3">TOTAL SEMUA ASET (tanpa filter losing)</td>
                            <td class="text-right mono">{{ $fmt($totals['revenue']) }}</td>
                            <td class="text-right mono">{{ $fmt($totals['cost']) }}</td>
                            <td class="text-right mono" style="color: {{ $totals['net'] < 0 ? 'var(--mt-accent-red, #dc2626)' : 'var(--mt-accent-green, #16a34a)' }};">
                                {{ $signed($totals['net']) }}
                            </td>
                            <td colspan="4"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px; padding: 12px; background: rgba(59, 130, 246, 0.08); border-radius: 6px; font-size: 12px; line-height: 1.6;">
                <strong>Cara Baca:</strong>
                <ul style="margin: 6px 0 0 18px; padding: 0;">
                    <li><strong>Usage</strong> diambil dari log fisik: <code>rental_logs.jam_kerja</code> (excavator/bulldozer/wheel_loader) atau <code>rit_logs.rit_count</code> (dump_truck). Channel dominan ditentukan dari <em>depreciation_method</em> aset; fallback ke <em>type</em>.</li>
                    <li><strong>Cost Total</strong> = BBM + Gaji + Premi/Uang Jalan + Maintenance + Penyusutan (semua yang tag <code>asset_id</code> di jurnal).</li>
                    <li><strong>Margin / Unit</strong> = Revenue per Unit − Cost per Unit. Baris <span style="background: rgba(220, 38, 38, 0.15); padding: 1px 6px; border-radius: 3px; color: var(--mt-accent-red, #dc2626);">merah muda dengan ⚠</span> = aset yang cost/unitnya lebih tinggi dari tarif rentalnya — evaluasi tarif atau divestasi.</li>
                    <li>Aset dengan Usage <code>–</code> berarti tidak ada log jam/rit di periode ini (idle atau kendaraan operasional non-produksi).</li>
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
