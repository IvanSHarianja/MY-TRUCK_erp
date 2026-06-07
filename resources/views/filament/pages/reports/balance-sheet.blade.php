<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php $fmt = fn ($n) => $n == 0 ? '–' : 'Rp ' . number_format($n, 0, ',', '.'); @endphp

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">Neraca &mdash; {{ $periodLabel }}</div>
        </div>

        <div class="report-bs-grid">
            {{-- KIRI: ASET --}}
            <div>
                <div class="report-bs-section">
                    <div class="report-bs-section-header">ASET</div>
                    <table class="report-table" style="border:none;">
                        <tbody>
                            <tr class="report-row-subcategory"><td colspan="3">Aset Lancar</td></tr>
                            @foreach ($asetLancar as $row)
                                <tr>
                                    <td class="code" style="width:80px;">{{ $row->code }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td class="text-right mono">{{ $fmt($row->saldo_debit) }}</td>
                                </tr>
                            @endforeach
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Aset Lancar</td>
                                <td class="text-right mono">{{ $fmt($totalAsetLancar) }}</td>
                            </tr>

                            <tr class="report-row-subcategory"><td colspan="3">Aset Tetap</td></tr>
                            @foreach ($asetTetap as $row)
                                <tr>
                                    <td class="code">{{ $row->code }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td class="text-right mono {{ $row->normal_balance === 'kredit' ? 'negative' : '' }}">
                                        @if ($row->normal_balance === 'kredit')
                                            ({{ $fmt($row->saldo_kredit) }})
                                        @else
                                            {{ $fmt($row->saldo_debit) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Aset Tetap (net)</td>
                                <td class="text-right mono">{{ $fmt($totalAsetTetap) }}</td>
                            </tr>

                            <tr class="report-row-total">
                                <td colspan="2" class="text-right">TOTAL ASET</td>
                                <td class="text-right mono">{{ $fmt($totalAset) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- KANAN: KEWAJIBAN + EKUITAS --}}
            <div style="display:flex;flex-direction:column;gap:1rem;">
                <div class="report-bs-section">
                    <div class="report-bs-section-header">KEWAJIBAN</div>
                    <table class="report-table" style="border:none;">
                        <tbody>
                            <tr class="report-row-subcategory"><td colspan="3">Kewajiban Lancar</td></tr>
                            @forelse ($kwjbLancar as $row)
                                <tr>
                                    <td class="code">{{ $row->code }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td class="text-right mono">{{ $fmt($row->saldo_kredit) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="muted">Tidak ada</td></tr>
                            @endforelse
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Lancar</td>
                                <td class="text-right mono">{{ $fmt($totalKwjbLancar) }}</td>
                            </tr>

                            <tr class="report-row-subcategory"><td colspan="3">Kewajiban Jangka Panjang</td></tr>
                            @forelse ($kwjbPanjang as $row)
                                <tr>
                                    <td class="code">{{ $row->code }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td class="text-right mono">{{ $fmt($row->saldo_kredit) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="muted">Tidak ada</td></tr>
                            @endforelse
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Panjang</td>
                                <td class="text-right mono">{{ $fmt($totalKwjbPanjang) }}</td>
                            </tr>

                            <tr class="report-row-total">
                                <td colspan="2" class="text-right">TOTAL KEWAJIBAN</td>
                                <td class="text-right mono">{{ $fmt($totalKewajiban) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="report-bs-section">
                    <div class="report-bs-section-header">EKUITAS</div>
                    <table class="report-table" style="border:none;">
                        <tbody>
                            @foreach ($ekuitas as $row)
                                <tr>
                                    <td class="code">{{ $row->code }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td class="text-right mono {{ $row->normal_balance === 'debit' ? 'negative' : '' }}">
                                        @if ($row->normal_balance === 'debit')
                                            ({{ $fmt($row->saldo_debit) }})
                                        @else
                                            {{ $fmt($row->saldo_kredit) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <td></td>
                                <td class="muted"><em>Laba/Rugi Tahun Berjalan (dari L/R)</em></td>
                                <td class="text-right mono">{{ $fmt($labaBerjalan) }}</td>
                            </tr>
                            <tr class="report-row-total">
                                <td colspan="2" class="text-right">TOTAL EKUITAS</td>
                                <td class="text-right mono">{{ $fmt($totalEkuitas) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="report-bs-total-card">
                    <div class="report-bs-total-card-label">TOTAL KEWAJIBAN + EKUITAS</div>
                    <div class="report-bs-total-card-value">{{ $fmt($totalPasiva) }}</div>
                </div>
            </div>
        </div>

        <div class="report-validation">
            @if ($isBalanced)
                <span class="report-badge report-badge-success">
                    &#10003; NERACA BALANCE &mdash; Aset {{ $fmt($totalAset) }} = Kewajiban + Ekuitas {{ $fmt($totalPasiva) }}
                </span>
            @else
                <span class="report-badge report-badge-danger">
                    &#10007; TIDAK BALANCE &mdash; Selisih {{ $fmt(abs($selisih)) }}
                </span>
            @endif
        </div>
    </div>
</x-filament-panels::page>
