<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $fmt = fn ($n) => $n == 0 ? '–' : 'Rp ' . number_format($n, 0, ',', '.');
        $netOperasi   = $kasMasukOperasi - $kasKeluarOperasi;
        $netInvestasi = $kasMasukInvestasi - $kasKeluarInvestasi;
        $netPendanaan = $kasMasukPendanaan - $kasKeluarPendanaan;
    @endphp

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">Laporan Arus Kas (Metode Langsung) &mdash; {{ $periodLabel }}</div>
        </div>

        <table class="report-table">
            <tbody>
                <tr class="report-row-subtotal">
                    <td>Saldo Kas Awal Periode</td>
                    <td class="text-right mono" style="width:240px;">{{ $fmt($saldoAwal) }}</td>
                </tr>

                {{-- I. OPERASI --}}
                <tr class="report-row-category">
                    <td colspan="2">I. Arus Kas dari Aktivitas Operasi</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Kas Masuk (Penerimaan)</td>
                    <td class="text-right mono">{{ $fmt($kasMasukOperasi) }}</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Kas Keluar (Pembayaran)</td>
                    <td class="text-right mono negative">({{ $fmt($kasKeluarOperasi) }})</td>
                </tr>
                <tr class="report-row-subtotal">
                    <td style="padding-left:2rem;">Kas Bersih Operasi</td>
                    <td class="text-right mono">{{ $fmt($netOperasi) }}</td>
                </tr>

                {{-- II. INVESTASI --}}
                <tr class="report-row-category">
                    <td colspan="2">II. Arus Kas dari Aktivitas Investasi</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Kas Masuk</td>
                    <td class="text-right mono">{{ $fmt($kasMasukInvestasi) }}</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Kas Keluar (pembelian aset)</td>
                    <td class="text-right mono negative">({{ $fmt($kasKeluarInvestasi) }})</td>
                </tr>
                <tr class="report-row-subtotal">
                    <td style="padding-left:2rem;">Kas Bersih Investasi</td>
                    <td class="text-right mono">{{ $fmt($netInvestasi) }}</td>
                </tr>

                {{-- III. PENDANAAN --}}
                <tr class="report-row-category">
                    <td colspan="2">III. Arus Kas dari Aktivitas Pendanaan</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Kas Masuk (utang / modal)</td>
                    <td class="text-right mono">{{ $fmt($kasMasukPendanaan) }}</td>
                </tr>
                <tr>
                    <td style="padding-left:2rem;">Kas Keluar (bayar utang / prive)</td>
                    <td class="text-right mono negative">({{ $fmt($kasKeluarPendanaan) }})</td>
                </tr>
                <tr class="report-row-subtotal">
                    <td style="padding-left:2rem;">Kas Bersih Pendanaan</td>
                    <td class="text-right mono">{{ $fmt($netPendanaan) }}</td>
                </tr>

                {{-- TOTAL --}}
                <tr class="report-row-total">
                    <td>Kenaikan / (Penurunan) Kas Bersih</td>
                    <td class="text-right mono">{{ $fmt($kenaikanBersih) }}</td>
                </tr>
                <tr class="report-row-grand">
                    <td>Saldo Kas Akhir Periode</td>
                    <td class="text-right mono">{{ $fmt($saldoAkhir) }}</td>
                </tr>
            </tbody>
        </table>

        <div style="padding:1rem 1.5rem;">
            <div class="report-note">
                💡 Saldo Kas Akhir di atas harus sama dengan saldo akun 111100 (Kas dan Bank) + 111110 (Kas Kecil) di Laporan Neraca.
            </div>
        </div>
    </div>
</x-filament-panels::page>
