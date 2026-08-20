<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Log Belum Ditagih — Potensi Cash-In</x-slot>
        <x-slot name="description">
            Diurut dari yang paling lama belum ditagih. Klik kontrak untuk aksi Tagih.
        </x-slot>

        @if (empty($rows))
            <div style="padding: 24px; text-align: center; color: var(--mt-accent-green, #059669);">
                ✓ Semua log operasional sudah ditagih. Tidak ada piutang tertunda.
            </div>
        @else
            @php
                $totalValue = array_sum(array_column($rows, 'estimated_value'));
            @endphp

            <div style="margin-bottom: 12px; padding: 10px 14px; background: rgba(245, 158, 11, 0.10); border-left: 3px solid #d97706; border-radius: 4px; font-size: 13px;">
                <strong>Total potensi Rp {{ number_format($totalValue, 0, ',', '.') }}</strong>
                dari {{ count($rows) }} kontrak — segera issue invoice supaya jadi piutang & cash-in lebih cepat.
            </div>

            <div style="overflow-x: auto;">
                <table class="report-table" style="min-width: 800px;">
                    <thead>
                        <tr>
                            <th style="width: 8%;">Lini</th>
                            <th style="width: 25%;">Kontrak / Pelanggan</th>
                            <th style="width: 12%;">Aset</th>
                            <th class="text-right" style="width: 15%;">Belum Ditagih</th>
                            <th class="text-right" style="width: 20%;">Estimasi Nilai</th>
                            <th style="width: 20%;">Umur Log Terlama</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $urgencyColor = $row['oldest_days_ago'] >= 30 ? '#dc2626'
                                    : ($row['oldest_days_ago'] >= 14 ? '#d97706' : '#0284c7');
                            @endphp
                            <tr>
                                <td>
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 3px; background: rgba(59, 130, 246, 0.15); color: #2563eb; font-weight: 600;">
                                        {{ $row['contract_type'] }}
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; font-size: 13px;">{{ $row['contract_number'] }}</div>
                                    <div style="font-size: 12px; opacity: 0.7;">{{ $row['client_name'] }}</div>
                                </td>
                                <td style="font-size: 12px;">{{ $row['asset_code'] }}</td>
                                <td class="text-right mono">
                                    {{ number_format($row['unbilled_qty'], 2, ',', '.') }} {{ $row['unit'] }}
                                </td>
                                <td class="text-right mono" style="font-weight: 700; color: #059669;">
                                    Rp {{ number_format($row['estimated_value'], 0, ',', '.') }}
                                </td>
                                <td style="font-size: 12px;">
                                    <span style="color: {{ $urgencyColor }}; font-weight: 600;">
                                        {{ $row['oldest_days_ago'] }} hari
                                    </span>
                                    <span style="opacity: 0.6;">sejak {{ \Carbon\Carbon::parse($row['oldest_log_date'])->format('d M Y') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
