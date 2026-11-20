<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $money = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format((float) $n, 0, ',', '.');
        $diff = fn ($n) => round((float) $n, 2) == 0 ? '–' : (($n > 0 ? '+' : '−') . number_format(abs((float) $n), 0, ',', '.'));
        $t = $report['totals'];
        $totStd = array_sum($t['standar']);
        $totAct = array_sum($t['aktual']);
    @endphp

    @if ($report['legacy']['count'] > 0)
        <div class="sva-note sva-warn">
            <strong>{{ $report['legacy']['count'] }} jurnal biaya standar lama</strong> (No. Bukti BBK-RT / BBK-RL, total Rp {{ $money($report['legacy']['total']) }})
            masih tercatat di bulan ini dan ikut di Laba Rugi. Jurnal ini dibuat otomatis dari log sebelum aturan biaya aktual berlaku.
            Bila biaya aktualnya sudah dicatat di <em>Transaksi &amp; Beban</em>, void jurnal lama tersebut di <em>Jurnal Umum</em> supaya biayanya tidak terhitung dua kali.
        </div>
    @endif

    @if ($report['untagged'] != 0)
        <div class="sva-note">
            Rp {{ $money($report['untagged']) }} biaya BBM / gaji / uang jalan di lini Armada &amp; Rental bulan ini <strong>belum ditandai unit</strong>,
            jadi tidak masuk kolom Aktual di bawah. Pilih <em>Unit</em> saat mencatat di Transaksi &amp; Beban.
        </div>
    @endif

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">Biaya Standar vs Aktual per Unit &mdash; {{ $periode }}</div>
        </div>

        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th rowspan="2">Unit</th>
                        <th rowspan="2">Kontrak</th>
                        @foreach ($components as $label)
                            <th colspan="2" class="text-center">{{ $label }}</th>
                        @endforeach
                        <th colspan="3" class="text-center">Total</th>
                    </tr>
                    <tr>
                        @foreach ($components as $label)
                            <th class="text-right">Standar</th>
                            <th class="text-right">Aktual</th>
                        @endforeach
                        <th class="text-right">Standar</th>
                        <th class="text-right">Aktual</th>
                        <th class="text-right">Selisih</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $r)
                        <tr>
                            <td class="gl-nowrap">
                                <span class="code">{{ $r['asset_code'] }}</span> {{ $r['name'] }}
                                @if (! empty($r['pemakaian']))
                                    <div class="muted text-xs">
                                        @isset($r['pemakaian']['rit']) {{ rtrim(rtrim(number_format($r['pemakaian']['rit'], 2, ',', '.'), '0'), ',') }} rit @endisset
                                        @isset($r['pemakaian']['jam']) {{ rtrim(rtrim(number_format($r['pemakaian']['jam'], 2, ',', '.'), '0'), ',') }} jam @endisset
                                    </div>
                                @endif
                            </td>
                            <td class="muted text-xs">{{ implode(', ', $r['kontrak']) ?: '–' }}</td>
                            @foreach (array_keys($components) as $k)
                                <td class="text-right mono muted">{{ $money($r['standar'][$k]) }}</td>
                                <td class="text-right mono">{{ $money($r['aktual'][$k]) }}</td>
                            @endforeach
                            <td class="text-right mono muted">{{ $money($r['total_standar']) }}</td>
                            <td class="text-right mono"><strong>{{ $money($r['total_aktual']) }}</strong></td>
                            <td class="text-right mono {{ $r['selisih'] > 0 ? 'sva-over' : ($r['selisih'] < 0 ? 'sva-under' : '') }}">
                                {{ $diff($r['selisih']) }}
                                @if ($r['selisih_pct'] !== null && $r['selisih'] != 0)
                                    <div class="text-xs">{{ $r['selisih_pct'] > 0 ? '+' : '' }}{{ number_format($r['selisih_pct'], 1, ',', '.') }}%</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 5 + 2 * count($components) }}" class="muted">Belum ada ritase / jam kerja maupun biaya unit pada bulan ini.</td></tr>
                    @endforelse
                    @if (count($report['rows']) > 1)
                        <tr class="report-row-grand">
                            <td colspan="2" class="text-right">TOTAL</td>
                            @foreach (array_keys($components) as $k)
                                <td class="text-right mono">{{ $money($t['standar'][$k]) }}</td>
                                <td class="text-right mono">{{ $money($t['aktual'][$k]) }}</td>
                            @endforeach
                            <td class="text-right mono">{{ $money($totStd) }}</td>
                            <td class="text-right mono">{{ $money($totAct) }}</td>
                            <td class="text-right mono">{{ $diff($totAct - $totStd) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted text-xs">
        <strong>Standar</strong> = tarif biaya di kontrak × ritase / jam kerja bulan ini (tidak dijurnal, hanya pembanding).
        <strong>Aktual</strong> = beban BBM, gaji &amp; makan, uang jalan &amp; premi yang dicatat keuangan dan ditandai unitnya — angka inilah yang masuk Laba Rugi.
        Selisih positif (merah) berarti biaya aktual lebih besar dari standar. Gaji bulanan supir yang dibayar per unit ikut di kolom Gaji.
    </p>

    <style>
        .sva-note { padding: .65rem .9rem; border-radius: .5rem; font-size: .85rem; background: rgb(59 130 246 / .08); }
        .sva-warn { background: rgb(245 158 11 / .12); }
        .sva-over { color: rgb(220 38 38); }
        .sva-under { color: rgb(22 163 74); }
    </style>
</x-filament-panels::page>
