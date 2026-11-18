@extends('pdf._layout', ['reportTitle' => 'BUKU BESAR'])

@php
    $money = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format((float) $n, 0, ',', '.');
    $saldo = fn ($n) => round((float) $n, 2) == 0
        ? '0'
        : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.'));
    $accounts = $ledger['accounts'];
@endphp

@section('content')
    @if ($accounts === [])
        <p>Tidak ada saldo maupun transaksi pada periode ini.</p>
    @else
        @if (count($accounts) > 1)
            <table class="rpt">
                <thead>
                    <tr>
                        <th style="width: 11%;">Kode</th>
                        <th>Nama Akun</th>
                        <th class="text-right" style="width: 14%;">Saldo Awal</th>
                        <th class="text-right" style="width: 14%;">Debit</th>
                        <th class="text-right" style="width: 14%;">Kredit</th>
                        <th class="text-right" style="width: 14%;">Saldo Akhir</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="section"><td colspan="6">RINGKASAN PER AKUN</td></tr>
                    @foreach ($accounts as $acc)
                        <tr>
                            <td style="font-family: monospace;">{{ $acc['code'] }}</td>
                            <td>{{ $acc['name'] }}</td>
                            <td class="text-right">{{ $saldo($acc['opening']) }}</td>
                            <td class="text-right">{{ $money($acc['debit']) }}</td>
                            <td class="text-right">{{ $money($acc['kredit']) }}</td>
                            <td class="text-right">{{ $saldo($acc['closing']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="grand">
                        <td colspan="3">TOTAL MUTASI</td>
                        <td class="text-right">{{ $money($ledger['totals']['debit']) }}</td>
                        <td class="text-right">{{ $money($ledger['totals']['kredit']) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        @endif

        @if ($ledger['truncated'])
            <p style="margin-top: 10px;">
                Rincian tidak dicetak: {{ number_format($ledger['line_count'], 0, ',', '.') }} baris jurnal terlalu banyak untuk PDF.
                Pilih satu akun / persempit tanggal, atau gunakan Export Excel.
            </p>
        @else
            @foreach ($accounts as $acc)
                <table class="rpt" style="margin-top: 14px; page-break-inside: auto;">
                    <thead>
                        <tr class="section">
                            <td colspan="7">
                                {{ $acc['code'] }} &middot; {{ $acc['name'] }}
                                (saldo normal {{ $acc['normal_balance'] === 'kredit' ? 'Kredit' : 'Debit' }})
                            </td>
                        </tr>
                        <tr>
                            <th style="width: 9%;">Tanggal</th>
                            <th style="width: 11%;">No. Jurnal</th>
                            <th style="width: 12%;">No. Bukti</th>
                            <th>Keterangan</th>
                            <th class="text-right" style="width: 12%;">Debit</th>
                            <th class="text-right" style="width: 12%;">Kredit</th>
                            <th class="text-right" style="width: 12%;">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ $dari->format('d/m/Y') }}</td>
                            <td colspan="5"><em>Saldo awal</em></td>
                            <td class="text-right">{{ $saldo($acc['opening']) }}</td>
                        </tr>
                        @foreach ($acc['lines'] as $l)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($l['date'])->format('d/m/Y') }}</td>
                                <td style="font-family: monospace;">{{ $l['entry_number'] }}</td>
                                <td style="font-family: monospace;">{{ $l['document_number'] ?? '–' }}</td>
                                <td>{{ $l['description'] }}</td>
                                <td class="text-right">{{ $money($l['debit']) }}</td>
                                <td class="text-right">{{ $money($l['kredit']) }}</td>
                                <td class="text-right">{{ $saldo($l['balance']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="grand">
                            <td colspan="4">Mutasi periode &amp; saldo akhir</td>
                            <td class="text-right">{{ $money($acc['debit']) }}</td>
                            <td class="text-right">{{ $money($acc['kredit']) }}</td>
                            <td class="text-right">{{ $saldo($acc['closing']) }}</td>
                        </tr>
                    </tbody>
                </table>
            @endforeach
        @endif

        <p style="margin-top: 10px; font-size: 8px; color: #555;">
            Hanya jurnal berstatus Posted; jurnal void dan pembaliknya tidak dihitung.
            Angka dalam kurung = saldo berlawanan dengan saldo normal akun.
        </p>
    @endif
@endsection
