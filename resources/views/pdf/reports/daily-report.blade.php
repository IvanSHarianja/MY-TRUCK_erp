@extends('pdf._layout', ['reportTitle' => 'LAPORAN HARIAN KEUANGAN'])

@php
    $money = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format((float) $n, 0, ',', '.');
    $saldo = fn ($n) => ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format((float) $n, 0, ',', '.'));
    $t = $report['totals'];
@endphp

@section('content')
    <table class="rpt">
        <thead>
            <tr>
                <th>Rekening</th>
                <th class="text-right" style="width: 16%;">Saldo Awal</th>
                <th class="text-right" style="width: 16%;">Masuk</th>
                <th class="text-right" style="width: 16%;">Keluar</th>
                <th class="text-right" style="width: 16%;">Saldo Akhir</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section"><td colspan="5">POSISI KAS PER REKENING</td></tr>
            @forelse ($report['rekening'] as $r)
                <tr>
                    <td>{{ $r['code'] }} — {{ $r['name'] }}</td>
                    <td class="text-right">{{ $saldo($r['opening']) }}</td>
                    <td class="text-right">{{ $money($r['in']) }}</td>
                    <td class="text-right">{{ $money($r['out']) }}</td>
                    <td class="text-right">{{ $saldo($r['closing']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Belum ada rekening kas/bank yang bersaldo.</td></tr>
            @endforelse
            <tr class="grand">
                <td>TOTAL</td>
                <td class="text-right">{{ $saldo($t['opening']) }}</td>
                <td class="text-right">{{ $money($t['in']) }}</td>
                <td class="text-right">{{ $money($t['out']) }}</td>
                <td class="text-right">{{ $saldo($t['closing']) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="rpt" style="margin-top: 14px;">
        <thead>
            <tr>
                <th style="width: 13%;">No. Bukti</th>
                <th style="width: 15%;">Rekening</th>
                <th>Keterangan</th>
                <th style="width: 18%;">Untuk / Dari</th>
                <th class="text-right" style="width: 12%;">Masuk</th>
                <th class="text-right" style="width: 12%;">Keluar</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section"><td colspan="6">RINCIAN KAS MASUK &amp; KELUAR</td></tr>
            @forelse ($report['mutasi'] as $m)
                <tr>
                    <td>{{ $m['document_number'] ?: $m['entry_number'] }}</td>
                    <td>{{ $m['rekening'] }}</td>
                    <td>{{ $m['description'] }}</td>
                    <td>{{ $m['lawan'] }}</td>
                    <td class="text-right">{{ $money($m['in']) }}</td>
                    <td class="text-right">{{ $money($m['out']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Tidak ada kas masuk / keluar pada tanggal ini.</td></tr>
            @endforelse
            <tr class="grand">
                <td colspan="4">TOTAL</td>
                <td class="text-right">{{ $money($t['in']) }}</td>
                <td class="text-right">{{ $money($t['out']) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="rpt" style="margin-top: 14px;">
        <thead>
            <tr><th style="width: 22%;">Jenis</th><th style="width: 18%;">Nomor</th><th>Pihak</th><th class="text-right" style="width: 16%;">Nominal</th></tr>
        </thead>
        <tbody>
            <tr class="section"><td colspan="4">TAGIHAN, PEMBAYARAN &amp; CICILAN HARI INI</td></tr>
            @foreach ($report['invoices'] as $inv)
                <tr><td>Invoice terbit</td><td>{{ $inv->invoice_number }}</td><td>{{ $inv->client?->name }}</td><td class="text-right">{{ $money($inv->amount) }}</td></tr>
            @endforeach
            @foreach ($report['payments'] as $p)
                <tr><td>Pembayaran diterima</td><td>{{ $p->invoice?->invoice_number }}</td><td>{{ $p->invoice?->client?->name }} → {{ $p->cashAccount?->name }}</td><td class="text-right">{{ $money($p->amount) }}</td></tr>
            @endforeach
            @foreach ($report['installments'] as $i)
                <tr><td>Cicilan dibayar</td><td>{{ $i->debt?->debt_number }}</td><td>{{ $i->debt?->kreditur }} (ke-{{ $i->ke }}/{{ $i->debt?->tenor_bulan }})</td><td class="text-right">{{ $money($i->paidTotal()) }}</td></tr>
            @endforeach
            @if ($report['invoices']->isEmpty() && $report['payments']->isEmpty() && $report['installments']->isEmpty())
                <tr><td colspan="4">Tidak ada.</td></tr>
            @endif

            @if ($report['due_invoices']->isNotEmpty() || $report['due_installments']->isNotEmpty())
                <tr class="section"><td colspan="4">JATUH TEMPO HARI INI</td></tr>
                @foreach ($report['due_invoices'] as $inv)
                    <tr><td>Piutang</td><td>{{ $inv->invoice_number }}</td><td>{{ $inv->client?->name }}</td><td class="text-right">{{ $money($inv->amount - $inv->paid_amount) }}</td></tr>
                @endforeach
                @foreach ($report['due_installments'] as $i)
                    <tr><td>Cicilan hutang</td><td>{{ $i->debt?->debt_number }}</td><td>{{ $i->debt?->kreditur }} (ke-{{ $i->ke }})</td><td class="text-right">{{ $money($i->total()) }}</td></tr>
                @endforeach
            @endif
        </tbody>
    </table>

    <p style="margin-top: 10px; font-size: 9px; color: #666;">
        Hanya jurnal Posted; jurnal void & pembaliknya tidak dihitung. Pindah dana antar rekening tercatat keluar di rekening asal dan masuk di rekening tujuan.
    </p>
@endsection
