{{--
    Posisi Kas per Rekening — dipakai halaman Arus Kas & Arus Kas (LAK).
    Variabel: $posisiKas (CashPositionService::getReport), $saldoAkhirLaporan (float, saldo akhir
    versi laporan induk — dipakai untuk menampilkan selisih kalau ada transaksi yang tidak ter-mapping).
--}}
@php
    $pkFmt = fn ($n) => round((float) $n, 2) == 0
        ? '–'
        : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.'));
    $pkNeg = fn ($n) => round((float) $n, 2) < 0 ? 'negative' : '';
    $pkSelisih = round($posisiKas['total']['saldo_akhir'] - (float) $saldoAkhirLaporan, 2);
    $pkAdaMinus = collect($posisiKas['rows'])->contains(fn ($r) => $r['saldo_akhir'] < 0);
@endphp

<div class="report-card" style="margin-top:1rem;">
    <div class="report-header">
        <div class="report-header-title">Posisi Kas per Rekening</div>
        <div class="report-header-subtitle">
            Transfer antar rekening tidak dihitung sebagai arus kas (total kas tidak berubah), tapi mutasinya terlihat di sini.
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Rekening</th>
                    <th class="text-right">Saldo Awal</th>
                    <th class="text-right">Kas Masuk</th>
                    <th class="text-right">Kas Keluar</th>
                    <th class="text-right">Transfer Masuk</th>
                    <th class="text-right">Transfer Keluar</th>
                    <th class="text-right">Saldo Akhir</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($posisiKas['rows'] as $row)
                    <tr>
                        <td class="code">{{ $row['code'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="text-right mono {{ $pkNeg($row['saldo_awal']) }}">{{ $pkFmt($row['saldo_awal']) }}</td>
                        <td class="text-right mono">{{ $pkFmt($row['masuk']) }}</td>
                        <td class="text-right mono">{{ $pkFmt($row['keluar']) }}</td>
                        <td class="text-right mono">{{ $pkFmt($row['transfer_masuk']) }}</td>
                        <td class="text-right mono">{{ $pkFmt($row['transfer_keluar']) }}</td>
                        <td class="text-right mono {{ $pkNeg($row['saldo_akhir']) }}"><strong>{{ $pkFmt($row['saldo_akhir']) }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Belum ada transaksi di rekening kas/bank.</td></tr>
                @endforelse
                <tr class="report-row-total">
                    <td colspan="2" class="text-right">TOTAL</td>
                    <td class="text-right mono {{ $pkNeg($posisiKas['total']['saldo_awal']) }}">{{ $pkFmt($posisiKas['total']['saldo_awal']) }}</td>
                    <td class="text-right mono">{{ $pkFmt($posisiKas['total']['masuk']) }}</td>
                    <td class="text-right mono">{{ $pkFmt($posisiKas['total']['keluar']) }}</td>
                    <td class="text-right mono">{{ $pkFmt($posisiKas['total']['transfer_masuk']) }}</td>
                    <td class="text-right mono">{{ $pkFmt($posisiKas['total']['transfer_keluar']) }}</td>
                    <td class="text-right mono {{ $pkNeg($posisiKas['total']['saldo_akhir']) }}">{{ $pkFmt($posisiKas['total']['saldo_akhir']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($pkAdaMinus || $pkSelisih != 0.0)
        <div style="padding:1rem 1.5rem;display:flex;flex-direction:column;gap:0.5rem;">
            @if ($pkAdaMinus)
                <div class="report-note">
                    ⚠️ Ada rekening bersaldo minus. Biasanya saldo awal rekening itu belum dicatat —
                    input lewat Jurnal Umum dengan Jenis Bukti <strong>Saldo Awal</strong>.
                </div>
            @endif
            @if ($pkSelisih != 0.0)
                <div class="report-note">
                    ⚠️ Total saldo akhir rekening berbeda Rp {{ number_format(abs($pkSelisih), 0, ',', '.') }}
                    dari saldo akhir laporan di atas. Ada transaksi kas yang akun lawannya belum
                    masuk pemetaan laporan ini — cek role akun lawan di Daftar Akun.
                </div>
            @endif
        </div>
    @endif
</div>
