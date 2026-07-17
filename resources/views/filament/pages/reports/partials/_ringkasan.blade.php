@php
    $fmt = fn ($n) => $n == 0 ? '–' : 'Rp ' . number_format($n, 0, ',', '.');
    $pct = fn ($n) => $totalPendapatan > 0 ? round($n / $totalPendapatan * 100, 1) . '%' : '–';
@endphp

<div class="report-card">
    <div class="report-header">
        <div class="report-header-title">{{ $companyName }}</div>
        <div class="report-header-subtitle">Laporan Laba Rugi &mdash; {{ $periodLabel }} &middot; {{ $unitLabel }}</div>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th>Kode</th>
                <th>Akun</th>
                <th class="text-right">Nilai</th>
                <th class="text-right">% Pendapatan</th>
            </tr>
        </thead>
        <tbody>
            {{-- I. PENDAPATAN --}}
            <tr class="report-row-category">
                <td colspan="4">I. Pendapatan Usaha</td>
            </tr>
            @foreach ($pendapatan as $row)
                <tr>
                    <td class="code">{{ $row->code }}</td>
                    <td>{{ $row->name }}</td>
                    <td class="text-right mono">{{ $fmt($row->saldo_kredit) }}</td>
                    <td class="text-right muted">{{ $pct($row->saldo_kredit) }}</td>
                </tr>
            @endforeach
            <tr class="report-row-subtotal">
                <td colspan="2" class="text-right">Total Pendapatan</td>
                <td class="text-right mono">{{ $fmt($totalPendapatan) }}</td>
                <td class="text-right">100%</td>
            </tr>

            {{-- II. HPP --}}
            <tr class="report-row-category">
                <td colspan="4">II. Beban Pokok Pendapatan (HPP)</td>
            </tr>
            @forelse ($hpp as $row)
                <tr>
                    <td class="code">{{ $row->code }}</td>
                    <td>{{ $row->name }}</td>
                    <td class="text-right mono negative">({{ $fmt($row->saldo_debit) }})</td>
                    <td class="text-right muted">{{ $pct($row->saldo_debit) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Belum ada beban HPP</td></tr>
            @endforelse
            <tr class="report-row-subtotal">
                <td colspan="2" class="text-right">Total HPP</td>
                <td class="text-right mono negative">({{ $fmt($totalHpp) }})</td>
                <td class="text-right">{{ $pct($totalHpp) }}</td>
            </tr>

            {{-- III. LABA KOTOR --}}
            <tr class="report-row-total">
                <td colspan="2" class="text-right">III. Laba Kotor</td>
                <td class="text-right mono">{{ $fmt($labaKotor) }}</td>
                <td class="text-right">{{ $pct($labaKotor) }}</td>
            </tr>

            {{-- IV. BEBAN OPERASIONAL --}}
            <tr class="report-row-category">
                <td colspan="4">IV. Beban Operasional</td>
            </tr>
            @forelse ($bebanOp as $row)
                <tr>
                    <td class="code">{{ $row->code }}</td>
                    <td>{{ $row->name }}</td>
                    <td class="text-right mono negative">({{ $fmt($row->saldo_debit) }})</td>
                    <td class="text-right muted">{{ $pct($row->saldo_debit) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Belum ada beban operasional</td></tr>
            @endforelse
            <tr class="report-row-subtotal">
                <td colspan="2" class="text-right">Total Beban Operasional</td>
                <td class="text-right mono negative">({{ $fmt($totalBebanOp) }})</td>
                <td class="text-right">{{ $pct($totalBebanOp) }}</td>
            </tr>

            {{-- V. LABA BERSIH --}}
            <tr class="report-row-grand">
                <td colspan="2" class="text-right">V. Laba Bersih Sebelum Pajak</td>
                <td class="text-right mono">{{ $fmt($labaBersih) }}</td>
                <td class="text-right">{{ $marginLaba }}%</td>
            </tr>
        </tbody>
    </table>
</div>
