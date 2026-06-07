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
            <div class="report-header-subtitle">Laporan Perubahan Ekuitas &mdash; {{ $periodLabel }}</div>
        </div>

        <table class="report-table">
            <tbody>
                <tr>
                    <td>Modal Pemilik (Saldo Awal)</td>
                    <td class="text-right mono" style="width:240px;">{{ $fmt($modalPemilik) }}</td>
                </tr>
                <tr>
                    <td>Laba Ditahan (Saldo Awal)</td>
                    <td class="text-right mono">{{ $fmt($labaDitahan) }}</td>
                </tr>
                <tr>
                    <td style="color:#047857;">(+) Laba Bersih Tahun Berjalan</td>
                    <td class="text-right mono" style="color:#047857;">{{ $fmt($labaBerjalan) }}</td>
                </tr>
                <tr>
                    <td class="negative">(&minus;) Prive / Pengambilan Modal</td>
                    <td class="text-right mono negative">({{ $fmt($prive) }})</td>
                </tr>
                <tr class="report-row-grand">
                    <td>TOTAL EKUITAS AKHIR</td>
                    <td class="text-right mono">{{ $fmt($totalEkuitas) }}</td>
                </tr>
            </tbody>
        </table>

        <div style="padding:1rem 1.5rem;">
            <div class="report-note">
                💡 Total Ekuitas Akhir di atas harus sama dengan Total Ekuitas di Laporan Neraca.
            </div>
        </div>
    </div>
</x-filament-panels::page>
