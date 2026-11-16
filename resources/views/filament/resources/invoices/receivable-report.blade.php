<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $fmt = fn ($n) => (float) $n != 0.0 ? 'Rp ' . number_format($n, 0, ',', '.') : '–';
    @endphp

    {{-- 1. Umur piutang per klien --}}
    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">Umur Piutang per Klien &mdash; posisi {{ $sampai->translatedFormat('d F Y') }}</div>
        </div>

        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Klien</th>
                        @foreach ($buckets as $label)
                            <th class="text-right">{{ $label }}</th>
                        @endforeach
                        <th class="text-right">Total Sisa</th>
                        <th class="text-right">Rata-rata Telat Bayar</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($aging as $row)
                        <tr>
                            <td>
                                <span class="code">{{ $row['client_code'] }}</span> {{ $row['client_name'] }}
                                @if ($row['jumlah_invoice'] > 0)
                                    <span class="muted">({{ $row['jumlah_invoice'] }} invoice)</span>
                                @endif
                            </td>
                            @foreach (array_keys($buckets) as $b)
                                <td class="text-right mono">{{ $fmt($row["bucket_{$b}"]) }}</td>
                            @endforeach
                            <td class="text-right mono"><strong>{{ $fmt($row['total_sisa']) }}</strong></td>
                            <td class="text-right">
                                @if ($row['rata_telat'] === null)
                                    <span class="muted">–</span>
                                @else
                                    {{ $row['rata_telat'] }} hari
                                    <div class="muted text-xs">{{ $row['lunas_telat'] }}/{{ $row['lunas_jumlah'] }} lunas telat</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($buckets) + 3 }}" class="muted">Tidak ada piutang pada tanggal ini.</td></tr>
                    @endforelse

                    <tr class="report-row-grand">
                        <td>TOTAL</td>
                        @foreach (array_keys($buckets) as $b)
                            <td class="text-right mono">{{ $fmt($totals["bucket_{$b}"]) }}</td>
                        @endforeach
                        <td class="text-right mono">{{ $fmt($totals['total_sisa']) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- 2. Invoice belum lunas --}}
    <div class="report-card">
        <div class="report-header">
            <div class="report-header-subtitle">Invoice Belum Lunas ({{ $outstanding->count() }}) &mdash; telat paling lama di atas</div>
        </div>

        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>No. Invoice</th>
                        <th>Klien</th>
                        <th>Tanggal</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-right">Nilai</th>
                        <th class="text-right">Dibayar</th>
                        <th class="text-right">Sisa</th>
                        <th class="text-right">Keterlambatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($outstanding as $inv)
                        <tr>
                            <td class="code">{{ $inv->invoice_number }}</td>
                            <td>{{ $inv->client_name }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($inv->invoice_date)->format('d/m/Y') }}</td>
                            <td>{{ $inv->due->format('d/m/Y') }}</td>
                            <td class="text-right mono">{{ $fmt($inv->amount) }}</td>
                            <td class="text-right mono muted">{{ $fmt($inv->paid) }}</td>
                            <td class="text-right mono"><strong>{{ $fmt($inv->sisa) }}</strong></td>
                            <td class="text-right">
                                @if ($inv->days_late > 0)
                                    <span class="report-badge {{ $inv->days_late > 30 ? 'report-badge-danger' : 'report-badge-warning' }}">Telat {{ $inv->days_late }} hari</span>
                                @else
                                    <span class="muted">Belum jatuh tempo</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">Semua invoice sudah lunas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 3. Riwayat pembayaran --}}
    <div class="report-card">
        <div class="report-header">
            <div class="report-header-subtitle">
                Riwayat Pembayaran {{ $dari->format('d/m/Y') }} – {{ $sampai->format('d/m/Y') }} ({{ $payments->count() }})
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>No. Pembayaran</th>
                        <th>No. Invoice</th>
                        <th>Klien</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-right">Nominal</th>
                        <th class="text-right">Ketepatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $p)
                        <tr @class(['muted' => $p->batal])>
                            <td>{{ \Illuminate\Support\Carbon::parse($p->payment_date)->format('d/m/Y') }}</td>
                            <td class="code">{{ $p->payment_number }} @if ($p->batal) <span class="report-badge report-badge-danger">Batal</span> @endif</td>
                            <td class="code">{{ $p->invoice_number }}</td>
                            <td>{{ $p->client_name }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($p->due_date ?? $p->invoice_date)->format('d/m/Y') }}</td>
                            <td class="text-right mono">{{ $fmt($p->amount) }}</td>
                            <td class="text-right">
                                @if ($p->days_late > 0)
                                    <span class="report-badge report-badge-warning">Telat {{ $p->days_late }} hari</span>
                                @else
                                    <span class="report-badge report-badge-success">Tepat waktu</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">Tidak ada pembayaran dalam periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
