<x-filament-panels::page>
    @php
        $rp = fn ($n) => ($n < 0 ? '(' : '') . 'Rp ' . number_format(abs((float) $n), 0, ',', '.') . ($n < 0 ? ')' : '');
    @endphp

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-subtitle">
                Tutup buku memindahkan laba/rugi setahun ke <strong>Laba Ditahan</strong> dan mengunci periodenya.
                Lakukan setelah semua transaksi & penyesuaian tahun itu selesai (penyusutan Desember, koreksi auditor).
                Tutup berurutan dari tahun terlama.
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Tahun</th>
                        <th class="text-right">Laba / (Rugi) Bersih</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($years as $row)
                        <tr>
                            <td class="mono"><strong>{{ $row['year'] }}</strong></td>
                            <td class="text-right mono">{{ $rp($row['profit']) }}</td>
                            <td>
                                @if ($row['closed'])
                                    <span class="report-badge report-badge-success">Ditutup</span>
                                @elseif ($row['year'] >= now()->year)
                                    <span class="report-badge">Tahun berjalan</span>
                                @else
                                    <span class="report-badge report-badge-warning">Belum ditutup</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($row['can_close'])
                                    {{ ($this->closeYearAction)(['year' => $row['year']]) }}
                                @elseif ($row['can_reopen'])
                                    {{ ($this->reopenYearAction)(['year' => $row['year']]) }}
                                @elseif (! $row['closed'] && $row['year'] < now()->year)
                                    <span class="muted text-xs">Tutup tahun sebelumnya dulu</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</x-filament-panels::page>
