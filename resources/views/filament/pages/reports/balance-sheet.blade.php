<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        // Semua angka dari BalanceSheetService sudah bertanda (`nilai`). Negatif
        // ditampilkan dalam kurung — saldo abnormal (mis. rekening bank minus
        // setelah transfer keluar) harus terlihat, bukan "–".
        $fmt = fn ($n) => round((float) $n, 2) == 0
            ? '–'
            : ($n < 0 ? '(Rp ' . number_format(abs($n), 0, ',', '.') . ')' : 'Rp ' . number_format($n, 0, ',', '.'));
        $neg = fn ($n) => round((float) $n, 2) < 0 ? 'negative' : '';
    @endphp

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
                                    <td class="text-right mono {{ $neg($row->nilai) }}">{{ $fmt($row->nilai) }}</td>
                                </tr>
                            @endforeach
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Aset Lancar</td>
                                <td class="text-right mono {{ $neg($totalAsetLancar) }}">{{ $fmt($totalAsetLancar) }}</td>
                            </tr>

                            <tr class="report-row-subcategory"><td colspan="3">Aset Tetap</td></tr>
                            @foreach ($asetTetap as $row)
                                <tr>
                                    <td class="code">{{ $row->code }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td class="text-right mono {{ $neg($row->nilai) }}">{{ $fmt($row->nilai) }}</td>
                                </tr>
                            @endforeach
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Aset Tetap (net)</td>
                                <td class="text-right mono {{ $neg($totalAsetTetap) }}">{{ $fmt($totalAsetTetap) }}</td>
                            </tr>

                            <tr class="report-row-total">
                                <td colspan="2" class="text-right">TOTAL ASET</td>
                                <td class="text-right mono {{ $neg($totalAset) }}">{{ $fmt($totalAset) }}</td>
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
                                    <td class="text-right mono {{ $neg($row->nilai) }}">{{ $fmt($row->nilai) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="muted">Tidak ada</td></tr>
                            @endforelse
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Lancar</td>
                                <td class="text-right mono {{ $neg($totalKwjbLancar) }}">{{ $fmt($totalKwjbLancar) }}</td>
                            </tr>

                            <tr class="report-row-subcategory"><td colspan="3">Kewajiban Jangka Panjang</td></tr>
                            @forelse ($kwjbPanjang as $row)
                                <tr>
                                    <td class="code">{{ $row->code }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td class="text-right mono {{ $neg($row->nilai) }}">{{ $fmt($row->nilai) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="muted">Tidak ada</td></tr>
                            @endforelse
                            <tr class="report-row-subtotal">
                                <td colspan="2" class="text-right">Total Panjang</td>
                                <td class="text-right mono {{ $neg($totalKwjbPanjang) }}">{{ $fmt($totalKwjbPanjang) }}</td>
                            </tr>

                            <tr class="report-row-total">
                                <td colspan="2" class="text-right">TOTAL KEWAJIBAN</td>
                                <td class="text-right mono {{ $neg($totalKewajiban) }}">{{ $fmt($totalKewajiban) }}</td>
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
                                    <td class="text-right mono {{ $neg($row->nilai) }}">{{ $fmt($row->nilai) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td></td>
                                <td class="muted"><em>Laba/Rugi Tahun Berjalan (dari L/R)</em></td>
                                <td class="text-right mono {{ $neg($labaBerjalan) }}">{{ $fmt($labaBerjalan) }}</td>
                            </tr>
                            <tr class="report-row-total">
                                <td colspan="2" class="text-right">TOTAL EKUITAS</td>
                                <td class="text-right mono {{ $neg($totalEkuitas) }}">{{ $fmt($totalEkuitas) }}</td>
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

        @if ($asetLancar->contains(fn ($row) => $row->nilai < 0))
            <div style="padding:0 1.5rem 1rem;">
                <div class="report-note">
                    ⚠️ Ada akun aset lancar bersaldo minus (dalam kurung). Untuk rekening kas/bank, ini biasanya
                    berarti saldo awal rekening tersebut belum dicatat — input lewat Jurnal Umum dengan
                    Jenis Bukti <strong>Saldo Awal</strong>.
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
