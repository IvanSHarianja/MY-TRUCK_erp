<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Utilization Aset (Bulan Ini)</x-slot>
        <x-slot name="description">
            Diurut dari yang paling kurang dipakai — aset merah = potensi rugi, aset hijau = peak.
        </x-slot>

        @if (empty($rows))
            <div style="padding: 24px; text-align: center; color: var(--mt-text-muted, #6b7280);">
                Belum ada aset aktif.
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="report-table" style="min-width: 700px;">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Aset</th>
                            <th class="text-right" style="width: 15%;">Jam Aktual</th>
                            <th class="text-right" style="width: 15%;">Target</th>
                            <th class="text-right" style="width: 15%;">Utilization</th>
                            <th style="width: 25%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $bgTint = match ($row['status']) {
                                    'peak'   => 'rgba(16, 185, 129, 0.08)',
                                    'normal' => 'transparent',
                                    'low'    => 'rgba(245, 158, 11, 0.08)',
                                    'idle'   => 'rgba(239, 68, 68, 0.08)',
                                };
                                $barColor = match ($row['status']) {
                                    'peak'   => '#059669',
                                    'normal' => '#0284c7',
                                    'low'    => '#d97706',
                                    'idle'   => '#dc2626',
                                };
                                $statusLabel = match ($row['status']) {
                                    'peak'   => '🟢 Peak — pertimbangkan tambah aset',
                                    'normal' => '🔵 Normal',
                                    'low'    => '🟡 Under-utilized — cari kontrak',
                                    'idle'   => '🔴 Idle — evaluasi penyewaan aktif',
                                };
                            @endphp
                            <tr style="background: {{ $bgTint }};">
                                <td>
                                    <div style="font-weight: 600;">[{{ $row['asset_code'] }}]</div>
                                    <div style="font-size: 12px; opacity: 0.7;">{{ $row['name'] }}</div>
                                </td>
                                <td class="text-right mono">{{ number_format($row['jam_actual'], 2, ',', '.') }} jam</td>
                                <td class="text-right mono" style="opacity: 0.6;">{{ number_format($row['jam_target'], 0, ',', '.') }} jam</td>
                                <td class="text-right mono" style="font-weight: 700; color: {{ $barColor }};">
                                    {{ number_format($row['utilization_pct'], 1, ',', '.') }}%
                                </td>
                                <td style="font-size: 12px;">{{ $statusLabel }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
