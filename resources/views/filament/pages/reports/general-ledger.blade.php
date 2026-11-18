<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        // Saldo negatif = berlawanan dengan saldo normal akun → ditulis dalam kurung.
        $money = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format((float) $n, 0, ',', '.');
        $saldo = fn ($n) => round((float) $n, 2) == 0
            ? '0'
            : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.'));
        $accounts = $ledger['accounts'];
        $period = $dari->translatedFormat('d M Y') . ' – ' . $sampai->translatedFormat('d M Y');
    @endphp

    @if ($accounts === [])
        <div class="report-card">
            <div class="report-header">
                <div class="report-header-title">{{ $companyName }}</div>
                <div class="report-header-subtitle">Buku Besar &mdash; {{ $period }}</div>
            </div>
            <p class="muted gl-empty">Tidak ada saldo maupun transaksi pada periode ini.</p>
        </div>
    @else
        {{-- Ringkasan per akun (selalu tampil bila lebih dari satu akun) --}}
        @if (count($accounts) > 1)
            <div class="report-card">
                <div class="report-header">
                    <div class="report-header-title">{{ $companyName }}</div>
                    <div class="report-header-subtitle">Buku Besar &mdash; Ringkasan per Akun &mdash; {{ $period }}</div>
                </div>

                @if ($ledger['truncated'])
                    <div class="coa-notice coa-notice-block gl-notice" role="status">
                        Ada <strong>{{ number_format($ledger['line_count'], 0, ',', '.') }} baris jurnal</strong> pada periode ini — terlalu banyak untuk ditampilkan rinci.
                        Pilih satu <strong>akun</strong>, persempit <strong>tanggal</strong>, atau klik <strong>Export Excel</strong> untuk rincian lengkap.
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama Akun</th>
                                <th class="text-right">Saldo Awal</th>
                                <th class="text-right">Debit</th>
                                <th class="text-right">Kredit</th>
                                <th class="text-right">Saldo Akhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $acc)
                                <tr>
                                    <td class="code">
                                        @if (! $ledger['truncated'])
                                            <a href="#gl-{{ $acc['id'] }}" class="gl-link">{{ $acc['code'] }}</a>
                                        @else
                                            {{ $acc['code'] }}
                                        @endif
                                    </td>
                                    <td>{{ $acc['name'] }}</td>
                                    <td class="text-right mono muted">{{ $saldo($acc['opening']) }}</td>
                                    <td class="text-right mono">{{ $money($acc['debit']) }}</td>
                                    <td class="text-right mono">{{ $money($acc['kredit']) }}</td>
                                    <td class="text-right mono"><strong>{{ $saldo($acc['closing']) }}</strong></td>
                                </tr>
                            @endforeach
                            <tr class="report-row-grand">
                                <td colspan="3" class="text-right">TOTAL MUTASI</td>
                                <td class="text-right mono">{{ $money($ledger['totals']['debit']) }}</td>
                                <td class="text-right mono">{{ $money($ledger['totals']['kredit']) }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Rincian per akun --}}
        @unless ($ledger['truncated'])
            @foreach ($accounts as $acc)
                <div class="report-card" id="gl-{{ $acc['id'] }}">
                    <div class="report-header gl-account-header">
                        <div>
                            <div class="report-header-title">{{ $acc['code'] }} &middot; {{ $acc['name'] }}</div>
                            <div class="report-header-subtitle">
                                {{ $period }} &middot; saldo normal {{ $acc['normal_balance'] === 'kredit' ? 'Kredit' : 'Debit' }}
                            </div>
                        </div>
                        <div class="gl-closing">
                            <span class="muted text-xs">Saldo akhir</span>
                            <span class="mono">Rp {{ $saldo($acc['closing']) }}</span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="report-table gl-table">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>No. Jurnal</th>
                                    <th>No. Bukti</th>
                                    <th>Keterangan</th>
                                    <th class="text-right">Debit</th>
                                    <th class="text-right">Kredit</th>
                                    <th class="text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="gl-row-opening">
                                    <td>{{ $dari->translatedFormat('d M Y') }}</td>
                                    <td colspan="5" class="muted">Saldo awal</td>
                                    <td class="text-right mono">{{ $saldo($acc['opening']) }}</td>
                                </tr>

                                @forelse ($acc['lines'] as $l)
                                    <tr>
                                        <td class="gl-nowrap">{{ \Illuminate\Support\Carbon::parse($l['date'])->translatedFormat('d M Y') }}</td>
                                        <td class="code gl-nowrap">
                                            @if ($journalUrl)
                                                <a href="{{ $journalUrl($l['entry_id']) }}" class="gl-link" wire:navigate>{{ $l['entry_number'] }}</a>
                                            @else
                                                {{ $l['entry_number'] }}
                                            @endif
                                        </td>
                                        <td class="code">{{ $l['document_number'] ?? '–' }}</td>
                                        <td>{{ $l['description'] }}</td>
                                        <td class="text-right mono">{{ $money($l['debit']) }}</td>
                                        <td class="text-right mono">{{ $money($l['kredit']) }}</td>
                                        <td class="text-right mono">{{ $saldo($l['balance']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="muted">Tidak ada transaksi pada periode ini.</td></tr>
                                @endforelse

                                <tr class="report-row-grand">
                                    <td colspan="4" class="text-right">Mutasi periode &amp; saldo akhir</td>
                                    <td class="text-right mono">{{ $money($acc['debit']) }}</td>
                                    <td class="text-right mono">{{ $money($acc['kredit']) }}</td>
                                    <td class="text-right mono">{{ $saldo($acc['closing']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endunless

        <p class="muted text-xs">
            Hanya jurnal berstatus Posted; jurnal void dan jurnal pembaliknya tidak ditampilkan.
            Angka dalam kurung = saldo berlawanan dengan saldo normal akun (mis. kas minus).
            Saldo akhir sama dengan saldo akun di Neraca Saldo per {{ $sampai->translatedFormat('d M Y') }}.
        </p>
    @endif
</x-filament-panels::page>
