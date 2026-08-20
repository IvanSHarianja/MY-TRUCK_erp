<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Stok Material Kritis</x-slot>
        <x-slot name="description">
            Material dengan stok mendekati habis. Estimasi hari tersisa dihitung dari rata-rata konsumsi 30 hari terakhir.
        </x-slot>

        @if (empty($rows))
            <div style="padding: 24px; text-align: center; color: var(--mt-accent-green, #059669);">
                ✓ Semua material stok aman. Tidak ada yang perlu re-order urgent.
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="report-table" style="min-width: 700px;">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Material</th>
                            <th class="text-right" style="width: 20%;">Stok Saat Ini</th>
                            <th class="text-right" style="width: 20%;">Konsumsi Rata2/Hari</th>
                            <th style="width: 30%;">Perkiraan Habis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $urgentColor = ($row['days_left'] !== null && $row['days_left'] <= 3) ? '#dc2626'
                                    : (($row['days_left'] !== null && $row['days_left'] <= 7) ? '#d97706' : '#0284c7');
                                $urgentLabel = $row['days_left'] === null
                                    ? 'Tidak ada konsumsi 30 hari — cek relevansi'
                                    : ($row['days_left'] <= 3
                                        ? '⚠ ' . $row['days_left'] . ' hari lagi — RE-ORDER SEGERA'
                                        : ($row['days_left'] <= 7
                                            ? '🟡 ' . $row['days_left'] . ' hari — siap-siap re-order'
                                            : '🟢 ' . $row['days_left'] . ' hari — masih aman'));
                            @endphp
                            <tr>
                                <td>
                                    <div style="font-weight: 600;">[{{ $row['code'] }}]</div>
                                    <div style="font-size: 12px; opacity: 0.7;">{{ $row['name'] }}</div>
                                </td>
                                <td class="text-right mono" style="font-weight: 700; color: {{ $urgentColor }};">
                                    {{ number_format($row['current_stock'], 2, ',', '.') }} {{ $row['satuan'] }}
                                </td>
                                <td class="text-right mono">
                                    {{ number_format($row['avg_consumption_30d'], 2, ',', '.') }} {{ $row['satuan'] }}
                                </td>
                                <td style="font-size: 12px; color: {{ $urgentColor }};">
                                    {{ $urgentLabel }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
