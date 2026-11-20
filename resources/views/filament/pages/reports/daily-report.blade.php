<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $money = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format((float) $n, 0, ',', '.');
        $saldo = fn ($n) => ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format((float) $n, 0, ',', '.'));
        $tgl = $report['date']->translatedFormat('l, d F Y');
        $t = $report['totals'];
    @endphp

    {{-- 1. Posisi kas per rekening --}}
    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">Laporan Harian Keuangan &mdash; {{ $tgl }}</div>
        </div>

        <div class="dr-kpis">
            <div class="dr-kpi"><span class="muted text-xs">Saldo awal hari</span><span class="mono">Rp {{ $saldo($t['opening']) }}</span></div>
            <div class="dr-kpi dr-in"><span class="muted text-xs">Kas masuk</span><span class="mono">Rp {{ $money($t['in']) }}</span></div>
            <div class="dr-kpi dr-out"><span class="muted text-xs">Kas keluar</span><span class="mono">Rp {{ $money($t['out']) }}</span></div>
            <div class="dr-kpi"><span class="muted text-xs">Saldo akhir hari</span><span class="mono"><strong>Rp {{ $saldo($t['closing']) }}</strong></span></div>
        </div>

        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Rekening</th>
                        <th class="text-right">Saldo Awal</th>
                        <th class="text-right">Masuk</th>
                        <th class="text-right">Keluar</th>
                        <th class="text-right">Saldo Akhir</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rekening'] as $r)
                        <tr>
                            <td><span class="code">{{ $r['code'] }}</span> {{ $r['name'] }}</td>
                            <td class="text-right mono muted">{{ $saldo($r['opening']) }}</td>
                            <td class="text-right mono">{{ $money($r['in']) }}</td>
                            <td class="text-right mono">{{ $money($r['out']) }}</td>
                            <td class="text-right mono"><strong>{{ $saldo($r['closing']) }}</strong></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Belum ada rekening kas/bank yang bersaldo.</td></tr>
                    @endforelse
                    @if (count($report['rekening']) > 1)
                        <tr class="report-row-grand">
                            <td class="text-right">TOTAL</td>
                            <td class="text-right mono">{{ $saldo($t['opening']) }}</td>
                            <td class="text-right mono">{{ $money($t['in']) }}</td>
                            <td class="text-right mono">{{ $money($t['out']) }}</td>
                            <td class="text-right mono">{{ $saldo($t['closing']) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- 2. Rincian mutasi kas --}}
    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">Rincian Kas Masuk &amp; Keluar</div>
            <div class="report-header-subtitle">{{ count($report['mutasi']) }} transaksi</div>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>No. Jurnal</th>
                        <th>No. Bukti</th>
                        <th>Rekening</th>
                        <th>Keterangan</th>
                        <th>Untuk / Dari</th>
                        <th class="text-right">Masuk</th>
                        <th class="text-right">Keluar</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['mutasi'] as $m)
                        <tr>
                            <td class="code gl-nowrap">
                                @if ($journalUrl)
                                    <a href="{{ $journalUrl($m['entry_id']) }}" class="gl-link" wire:navigate>{{ $m['entry_number'] }}</a>
                                @else
                                    {{ $m['entry_number'] }}
                                @endif
                            </td>
                            <td class="code">{{ $m['document_number'] ?? '–' }}</td>
                            <td>{{ $m['rekening'] }}</td>
                            <td>{{ $m['description'] }}</td>
                            <td class="muted">{{ $m['lawan'] }}</td>
                            <td class="text-right mono">{{ $money($m['in']) }}</td>
                            <td class="text-right mono">{{ $money($m['out']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">Tidak ada kas masuk / keluar pada tanggal ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 3. Tagihan, pembayaran, cicilan --}}
    <div class="dr-grid">
        <div class="report-card">
            <div class="report-header">
                <div class="report-header-title">Invoice Terbit</div>
                <div class="report-header-subtitle">Rp {{ $money($report['invoices']->sum('amount')) }}</div>
            </div>
            <table class="report-table">
                <tbody>
                    @forelse ($report['invoices'] as $inv)
                        <tr>
                            <td class="code">{{ $inv->invoice_number }}</td>
                            <td>{{ $inv->client?->name }}</td>
                            <td class="text-right mono">{{ $money($inv->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td class="muted">Tidak ada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="report-card">
            <div class="report-header">
                <div class="report-header-title">Pembayaran Diterima</div>
                <div class="report-header-subtitle">Rp {{ $money($report['payments']->sum('amount')) }}</div>
            </div>
            <table class="report-table">
                <tbody>
                    @forelse ($report['payments'] as $p)
                        <tr>
                            <td class="code">{{ $p->invoice?->invoice_number }}</td>
                            <td>{{ $p->invoice?->client?->name }} <span class="muted text-xs">→ {{ $p->cashAccount?->name }}</span></td>
                            <td class="text-right mono">{{ $money($p->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td class="muted">Tidak ada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="report-card">
            <div class="report-header">
                <div class="report-header-title">Cicilan Hutang Dibayar</div>
                <div class="report-header-subtitle">Rp {{ $money($report['installments']->sum(fn ($i) => $i->paidTotal())) }}</div>
            </div>
            <table class="report-table">
                <tbody>
                    @forelse ($report['installments'] as $i)
                        <tr>
                            <td>{{ $i->debt?->kreditur }} <span class="muted text-xs">ke-{{ $i->ke }}/{{ $i->debt?->tenor_bulan }}</span></td>
                            <td class="text-right mono">{{ $money($i->paidTotal()) }}</td>
                        </tr>
                    @empty
                        <tr><td class="muted">Tidak ada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 4. Perlu perhatian --}}
    @if ($report['due_invoices']->isNotEmpty() || $report['due_installments']->isNotEmpty())
        <div class="report-card">
            <div class="report-header">
                <div class="report-header-title">Jatuh Tempo Hari Ini</div>
            </div>
            <table class="report-table">
                <tbody>
                    @foreach ($report['due_invoices'] as $inv)
                        <tr>
                            <td>Piutang</td>
                            <td class="code">{{ $inv->invoice_number }}</td>
                            <td>{{ $inv->client?->name }}</td>
                            <td class="text-right mono">{{ $money($inv->amount - $inv->paid_amount) }}</td>
                        </tr>
                    @endforeach
                    @foreach ($report['due_installments'] as $i)
                        <tr>
                            <td>Cicilan hutang</td>
                            <td class="code">{{ $i->debt?->debt_number }}</td>
                            <td>{{ $i->debt?->kreditur }} (ke-{{ $i->ke }})</td>
                            <td class="text-right mono">{{ $money($i->total()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="muted text-xs">
        Hanya jurnal berstatus Posted; jurnal void dan pembaliknya tidak dihitung. Saldo akhir hari = saldo rekening di Buku Besar per tanggal ini.
        Pindah dana antar rekening tercatat sebagai keluar di rekening asal dan masuk di rekening tujuan (total kas tidak berubah).
    </p>

    <style>
        .dr-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin: .5rem 0 1rem; }
        .dr-kpi { display: flex; flex-direction: column; gap: .15rem; padding: .6rem .8rem; border-radius: .5rem; background: rgb(0 0 0 / .03); }
        .dark .dr-kpi { background: rgb(255 255 255 / .04); }
        .dr-in .mono { color: rgb(22 163 74); }
        .dr-out .mono { color: rgb(220 38 38); }
        .dr-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
        @media (max-width: 1024px) { .dr-kpis, .dr-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .dr-kpis, .dr-grid { grid-template-columns: 1fr; } }
    </style>
</x-filament-panels::page>
