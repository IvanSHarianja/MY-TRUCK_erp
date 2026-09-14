{{--
    Posisi Kas per Rekening (PDF) — dipakai PDF Arus Kas & Arus Kas LAK.
    Variabel: $posisiKas (CashPositionService::getReport), $saldoAkhirLaporan (float).
--}}
@php
    $pkFmt = fn ($n) => round((float) $n, 2) == 0
        ? '–'
        : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.'));
    $pkSelisih = round($posisiKas['total']['saldo_akhir'] - (float) $saldoAkhirLaporan, 2);
@endphp

<table class="rpt" style="margin-top: 14px;">
    <thead>
        <tr>
            <th colspan="7" style="text-align: left;">POSISI KAS PER REKENING</th>
        </tr>
        <tr>
            <th style="text-align: left;">Rekening</th>
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
                <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                <td class="text-right">{{ $pkFmt($row['saldo_awal']) }}</td>
                <td class="text-right">{{ $pkFmt($row['masuk']) }}</td>
                <td class="text-right">{{ $pkFmt($row['keluar']) }}</td>
                <td class="text-right">{{ $pkFmt($row['transfer_masuk']) }}</td>
                <td class="text-right">{{ $pkFmt($row['transfer_keluar']) }}</td>
                <td class="text-right">{{ $pkFmt($row['saldo_akhir']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Belum ada transaksi di rekening kas/bank.</td></tr>
        @endforelse
        <tr class="grand">
            <td>TOTAL</td>
            <td class="text-right">{{ $pkFmt($posisiKas['total']['saldo_awal']) }}</td>
            <td class="text-right">{{ $pkFmt($posisiKas['total']['masuk']) }}</td>
            <td class="text-right">{{ $pkFmt($posisiKas['total']['keluar']) }}</td>
            <td class="text-right">{{ $pkFmt($posisiKas['total']['transfer_masuk']) }}</td>
            <td class="text-right">{{ $pkFmt($posisiKas['total']['transfer_keluar']) }}</td>
            <td class="text-right">{{ $pkFmt($posisiKas['total']['saldo_akhir']) }}</td>
        </tr>
    </tbody>
</table>

<div style="margin-top:6px; font-size:8px; color:#666;">
    Transfer antar rekening tidak dihitung sebagai arus kas karena total kas tidak berubah.
    @if ($pkSelisih != 0.0)
        <br>Catatan: total saldo akhir rekening berbeda {{ number_format(abs($pkSelisih), 0, ',', '.') }}
        dari saldo akhir laporan — ada transaksi kas dengan akun lawan yang belum ter-mapping.
    @endif
</div>
