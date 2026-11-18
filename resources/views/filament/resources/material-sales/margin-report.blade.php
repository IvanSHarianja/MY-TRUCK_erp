<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $rp = fn ($n) => round((float) $n, 2) == 0 ? '–' : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.'));
        $t = $report['totals'];
    @endphp

    <div class="asset-import-summary">
        <div><span class="asset-import-num">Rp {{ number_format($t['pendapatan'], 0, ',', '.') }}</span> penjualan</div>
        <div><span class="asset-import-num">Rp {{ number_format($t['hpp'] + $t['kirim'], 0, ',', '.') }}</span> HPP + biaya kirim</div>
        <div><span class="asset-import-num" @style(['color: var(--mt-accent-red)' => $t['margin'] < 0])>Rp {{ number_format($t['margin'], 0, ',', '.') }}</span> margin ({{ $t['pct'] }}%)</div>
    </div>

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-subtitle">
                Margin per Penjualan &mdash; {{ $dari->translatedFormat('d M Y') }} – {{ $sampai->translatedFormat('d M Y') }}
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>No. Penjualan</th>
                        <th>Klien</th>
                        <th>Material</th>
                        <th>Jumlah</th>
                        <th>Truk</th>
                        <th class="text-right">Penjualan</th>
                        <th class="text-right">HPP</th>
                        <th class="text-right">Biaya Kirim</th>
                        <th class="text-right">Margin</th>
                        <th class="text-right">%</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $r)
                        @php($s = $r['sale'])
                        <tr>
                            <td class="gl-nowrap">{{ $s->sale_date->translatedFormat('d M Y') }}</td>
                            <td class="code">{{ $s->sale_number }}</td>
                            <td>{{ $s->client?->name }}</td>
                            <td>{{ $s->material?->name }}</td>
                            <td class="gl-nowrap">{{ $s->quantityLabel() }}</td>
                            <td class="code">{{ $s->asset?->asset_code ?? '–' }}</td>
                            <td class="text-right mono">{{ $rp($r['pendapatan']) }}</td>
                            <td class="text-right mono muted">{{ $rp($r['hpp']) }}</td>
                            <td class="text-right mono muted">{{ $rp($r['kirim']) }}</td>
                            <td class="text-right mono"><strong>{{ $rp($r['margin']) }}</strong></td>
                            <td class="text-right">
                                <span @class(['report-badge', 'report-badge-danger' => $r['margin'] < 0, 'report-badge-success' => $r['margin'] >= 0])>{{ $r['pct'] }}%</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="muted">Tidak ada penjualan pada periode ini.</td></tr>
                    @endforelse
                    @if ($report['rows']->isNotEmpty())
                        <tr class="report-row-grand">
                            <td colspan="6" class="text-right">TOTAL</td>
                            <td class="text-right mono">{{ $rp($t['pendapatan']) }}</td>
                            <td class="text-right mono">{{ $rp($t['hpp']) }}</td>
                            <td class="text-right mono">{{ $rp($t['kirim']) }}</td>
                            <td class="text-right mono">{{ $rp($t['margin']) }}</td>
                            <td class="text-right">{{ $t['pct'] }}%</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted text-xs">
        Margin = penjualan − HPP (harga pokok material dari stok) − biaya pengiriman (ongkos supir, uang makan, BBM, BM, portal, lain-lain).
        Penjualan yang invoice-nya di-void tidak dihitung. Angka dalam kurung = rugi.
    </p>
</x-filament-panels::page>
