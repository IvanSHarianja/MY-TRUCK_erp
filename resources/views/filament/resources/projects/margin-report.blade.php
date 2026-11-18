<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $rp = fn ($n) => round((float) $n, 2) == 0 ? '–' : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.'));
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        $t = $report['totals'];
    @endphp

    <div class="asset-import-summary">
        <div><span class="asset-import-num">Rp {{ number_format($t['pendapatan'], 0, ',', '.') }}</span> pendapatan termin</div>
        <div><span class="asset-import-num">Rp {{ number_format($t['biaya'], 0, ',', '.') }}</span> biaya unit</div>
        <div><span class="asset-import-num" @style(['color: var(--mt-accent-red)' => $t['margin'] < 0])>Rp {{ number_format($t['margin'], 0, ',', '.') }}</span> margin ({{ $t['pct'] }}%)</div>
    </div>

    <div class="report-card">
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Proyek</th>
                        <th>Klien</th>
                        <th class="text-right">Nilai Kontrak</th>
                        <th class="text-right">Progress / Tertagih</th>
                        <th class="text-right">Pendapatan</th>
                        <th class="text-right">Biaya Unit</th>
                        <th class="text-right">Penyusutan</th>
                        <th class="text-right">Margin</th>
                        <th class="text-right">%</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $r)
                        @php($p = $r['project'])
                        <tr>
                            <td>
                                <span class="code">{{ $p->project_number }}</span> {{ $p->name }}
                                @if ($r['units']->isNotEmpty())
                                    <details class="pm-units">
                                        <summary class="muted text-xs">{{ $r['units']->count() }} unit dipakai — lihat rincian</summary>
                                        <table class="pm-units-table">
                                            @foreach ($r['units'] as $u)
                                                <tr>
                                                    <td class="code">{{ $u['asset']?->asset_code }}</td>
                                                    <td>{{ $u['hari'] }} hari{{ $u['jam'] > 0 ? ' · ' . $num($u['jam']) . ' jam' : '' }}{{ $u['rit'] > 0 ? ' · ' . $u['rit'] . ' rit' : '' }}</td>
                                                    <td class="text-right mono">Rp {{ $rp($u['biaya_kas'] + $u['penyusutan']) }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </details>
                                @endif
                            </td>
                            <td>{{ $p->client?->name }}</td>
                            <td class="text-right mono muted">{{ $rp($p->nilai_kontrak) }}</td>
                            <td class="text-right">{{ $num($p->progress_pct) }}% / {{ $num($p->tertagih_pct) }}%</td>
                            <td class="text-right mono">{{ $rp($r['pendapatan']) }}</td>
                            <td class="text-right mono muted">{{ $rp($r['biaya_kas']) }}</td>
                            <td class="text-right mono muted">{{ $rp($r['penyusutan']) }}</td>
                            <td class="text-right mono"><strong>{{ $rp($r['margin']) }}</strong></td>
                            <td class="text-right">
                                @if ($r['pct'] === null)
                                    <span class="muted">–</span>
                                @else
                                    <span @class(['report-badge', 'report-badge-danger' => $r['margin'] < 0, 'report-badge-success' => $r['margin'] >= 0])>{{ $r['pct'] }}%</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="muted">Belum ada proyek.</td></tr>
                    @endforelse
                    @if ($report['rows']->isNotEmpty())
                        <tr class="report-row-grand">
                            <td colspan="4" class="text-right">TOTAL</td>
                            <td class="text-right mono">{{ $rp($t['pendapatan']) }}</td>
                            <td colspan="2" class="text-right mono">{{ $rp($t['biaya']) }}</td>
                            <td class="text-right mono">{{ $rp($t['margin']) }}</td>
                            <td class="text-right">{{ $t['pct'] }}%</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted text-xs">
        Pendapatan = nilai termin yang sudah ditagih (termasuk bagian yang dipotong dari uang muka).
        Biaya unit = BBM, gaji, uang makan, premi & lain-lain dari tab <strong>Pemakaian Unit Harian</strong>;
        penyusutan = penyusutan per jam/rit/hari unit yang dipakai.
        Biaya lain yang dijurnal manual (subkontraktor, material) belum terhubung ke proyek.
    </p>
</x-filament-panels::page>
