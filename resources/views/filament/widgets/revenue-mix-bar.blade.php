<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Komposisi Pendapatan per Lini Bisnis
        </x-slot>

        <x-slot name="description">
            {{ $periodLabel }}
        </x-slot>

        @php
            $fmt = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
        @endphp

        @if ($totalRevenue > 0)
            {{-- Stacked bar --}}
            <div style="display: flex; height: 36px; border-radius: 8px; overflow: hidden; border: 1px solid rgb(var(--gray-200)); margin-bottom: 16px;">
                @foreach ($segments as $seg)
                    <div
                        style="background: {{ $seg['color'] }}; width: {{ $seg['percentage'] }}%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 11px; white-space: nowrap; overflow: hidden; min-width: 0;"
                        title="{{ $seg['name'] }}: {{ $fmt($seg['amount']) }} ({{ $seg['percentage'] }}%)"
                    >
                        @if ($seg['percentage'] >= 8)
                            {{ $seg['code'] }} · {{ $seg['percentage'] }}%
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Legend --}}
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                @foreach ($segments as $seg)
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 14px; height: 14px; border-radius: 3px; background: {{ $seg['color'] }}; flex-shrink: 0;"></div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 12.5px;">{{ $seg['name'] }}</div>
                            <div style="font-size: 12px; color: rgb(var(--gray-500)); font-variant-numeric: tabular-nums;">
                                <strong>{{ $fmt($seg['amount']) }}</strong>
                                <span style="opacity: 0.7;">({{ $seg['percentage'] }}%)</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Total --}}
            <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid rgb(var(--gray-200)); display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 600; color: rgb(var(--gray-700));">Total Pendapatan</span>
                <span style="font-weight: 800; font-size: 16px; color: rgb(var(--primary-600));">{{ $fmt($totalRevenue) }}</span>
            </div>
        @else
            <div style="text-align: center; padding: 32px; color: rgb(var(--gray-500));">
                Belum ada pendapatan pada periode ini.
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
