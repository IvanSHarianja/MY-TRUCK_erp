<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $fmt = fn ($n) => $n > 0 ? 'Rp ' . number_format($n, 2, ',', '.') : '–';
        $categoryLabels = [
            'aset'       => 'ASET',
            'kewajiban'  => 'KEWAJIBAN',
            'ekuitas'    => 'EKUITAS',
            'pendapatan' => 'PENDAPATAN',
            'beban'      => 'BEBAN',
            'penutup'    => 'AKUN PENUTUP',
        ];
    @endphp

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">Neraca Saldo &mdash; {{ $periodLabel }}</div>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Akun</th>
                    <th class="text-right">Total Debit</th>
                    <th class="text-right">Total Kredit</th>
                    <th class="text-right">Saldo Debit</th>
                    <th class="text-right">Saldo Kredit</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($byCategory as $cat => $rows)
                    @if ($rows->count() > 0)
                        <tr class="report-row-category">
                            <td colspan="6">{{ $categoryLabels[$cat] ?? strtoupper($cat) }}</td>
                        </tr>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="code">{{ $row->code }}</td>
                                <td>{{ $row->name }}</td>
                                <td class="text-right mono muted">{{ $fmt($row->total_debit) }}</td>
                                <td class="text-right mono muted">{{ $fmt($row->total_kredit) }}</td>
                                <td class="text-right mono">{{ $fmt($row->saldo_debit) }}</td>
                                <td class="text-right mono">{{ $fmt($row->saldo_kredit) }}</td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach

                <tr class="report-row-grand">
                    <td colspan="4" class="text-right">GRAND TOTAL</td>
                    <td class="text-right mono">Rp {{ number_format($grandTotal['total_debit'], 2, ',', '.') }}</td>
                    <td class="text-right mono">Rp {{ number_format($grandTotal['total_kredit'], 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        <div class="report-validation">
            @if ($grandTotal['is_balanced'])
                <span class="report-badge report-badge-success">
                    &#10003; NERACA SALDO BALANCE
                </span>
            @else
                <span class="report-badge report-badge-danger">
                    &#10007; TIDAK BALANCE (selisih Rp {{ number_format(abs($grandTotal['total_debit'] - $grandTotal['total_kredit']), 2, ',', '.') }})
                </span>
            @endif
        </div>
    </div>
</x-filament-panels::page>
