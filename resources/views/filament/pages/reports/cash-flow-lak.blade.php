<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        // Kosong kalau 0 — mirror Excel yang blank untuk cell 0.
        $fmt = fn ($n) => (float) $n == 0.0 ? '' : number_format($n, 0, ',', '.');
    @endphp

    {{--
      Style scoped ke halaman ini via <style> tag — inline supaya tidak
      polusi filament-custom.css global. Palet warna mirror Excel LAK.
    --}}
    <style>
        .lak-wrapper {
            background: #fff;
            padding: 1.25rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        .lak-header {
            text-align: center;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #111;
            margin-bottom: 0.75rem;
        }
        .lak-header h2 {
            font-weight: 800;
            font-size: 1.1rem;
            letter-spacing: 1px;
            margin: 0;
            color: #0F172A;
        }
        .lak-header .subtitle {
            font-size: 0.9rem;
            color: #555;
            margin-top: 2px;
        }
        table.lak {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            font-family: 'Segoe UI', 'Poppins', sans-serif;
        }
        table.lak td, table.lak th {
            border: 1px solid #333;
            padding: 6px 10px;
            vertical-align: middle;
        }
        table.lak th {
            background: #E5E7EB;
            font-weight: 700;
            text-align: center;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table.lak td.maping    { width: 22%; text-transform: uppercase; }
        table.lak td.desc      { width: 42%; }
        table.lak td.detail,
        table.lak td.subtotal  {
            width: 14%; text-align: right;
            font-family: 'JetBrains Mono', 'Consolas', monospace;
        }
        table.lak td.bold      { font-weight: 700; }
        table.lak td.center    { text-align: center; }
        table.lak td.red       { color: #DC2626; }

        /* Section header (Penerimaan :, Pengeluaran Expedisi :) — abu-abu bold */
        table.lak tr.section td {
            font-weight: 700;
            background: #F3F4F6;
        }
        /* Subtotal per section → kuning Excel */
        table.lak tr.subtot td {
            font-weight: 700;
            background: #FFFF00;
        }
        /* Grand total (Total Penerimaan/Pengeluaran) → hijau Excel */
        table.lak tr.grand td {
            font-weight: 700;
            background: #00FF00;
            text-align: center;
        }
        table.lak tr.grand td.subtotal { text-align: right; }
        /* Saldo akhir → cyan Excel */
        table.lak tr.saldo td {
            font-weight: 800;
            background: #00FFFF;
            font-size: 0.95rem;
        }
        table.lak tr.spacer td { border: none; height: 8px; background: transparent; }

        /* Dark mode — warna Excel dipertahankan tapi kontras text disesuaikan */
        .dark .lak-wrapper { background: #1F2937; }
        .dark .lak-header  { border-bottom-color: #E5E7EB; }
        .dark .lak-header h2 { color: #F9FAFB; }
        .dark .lak-header .subtitle { color: #D1D5DB; }
        .dark table.lak td, .dark table.lak th { color: #111; border-color: #6B7280; }
        .dark table.lak td:not([class*="subtot"]):not([class*="grand"]):not([class*="saldo"]):not([class*="section"]) {
            background: #F9FAFB;  /* baris data putih tulang di dark mode supaya angka kebaca */
        }
    </style>

    <div class="lak-wrapper">
        <div class="lak-header">
            <h2>{{ $companyName }}</h2>
            <div class="subtitle">LAPORAN ARUS KAS &mdash; {{ $periodLabel }}</div>
        </div>

        <table class="lak">
            <thead>
                <tr>
                    <th>MAPING</th>
                    <th>Transaksi</th>
                    <th>Detail</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                {{-- SALDO AWAL --}}
                <tr>
                    <td class="maping bold">SALDO AWAL</td>
                    <td class="desc bold">Saldo Awal Kas & Bank</td>
                    <td class="detail"></td>
                    <td class="subtotal bold">{{ $fmt($saldoAwal) }}</td>
                </tr>

                {{-- PENERIMAAN --}}
                <tr class="section">
                    <td colspan="4">Penerimaan :</td>
                </tr>
                @foreach ($penerimaan as $row)
                    <tr>
                        <td class="maping">{{ $row['label'] }}</td>
                        <td class="desc">{{ $row['description'] }}</td>
                        <td class="detail">{{ $fmt($row['amount']) }}</td>
                        <td class="subtotal"></td>
                    </tr>
                @endforeach
                <tr class="grand">
                    <td colspan="2">Total Penerimaan</td>
                    <td class="detail"></td>
                    <td class="subtotal">{{ $fmt($totalPenerimaan) }}</td>
                </tr>

                {{-- PENGELUARAN per section --}}
                @foreach ($pengeluaran as $section)
                    <tr class="section">
                        <td colspan="4">{{ $section['title'] }} :</td>
                    </tr>
                    @foreach ($section['items'] as $row)
                        <tr>
                            <td class="maping">{{ $row['label'] }}</td>
                            <td class="desc">{{ $row['description'] }}</td>
                            <td class="detail red">{{ $fmt($row['amount']) }}</td>
                            <td class="subtotal"></td>
                        </tr>
                    @endforeach
                    <tr class="subtot">
                        <td colspan="2">Total {{ $section['title'] }}</td>
                        <td class="detail"></td>
                        <td class="subtotal">{{ $fmt($section['total']) }}</td>
                    </tr>
                @endforeach

                {{-- Grand total pengeluaran --}}
                <tr class="grand">
                    <td colspan="2">Total Pengeluaran</td>
                    <td class="detail"></td>
                    <td class="subtotal">{{ $fmt($totalPengeluaran) }}</td>
                </tr>

                <tr class="spacer"><td colspan="4"></td></tr>

                {{-- SALDO AKHIR --}}
                <tr class="saldo">
                    <td colspan="3" class="center">SALDO AKHIR KAS & BANK</td>
                    <td class="subtotal">Rp {{ $fmt($saldoAkhir) }}</td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 0.75rem; font-size: 0.75rem; color: #6B7280;">
            💡 Format ini mirror layout Excel LAK operasional. Untuk laporan format standar PSAK
            (Operasi / Investasi / Pendanaan), lihat menu <strong>Arus Kas</strong>.
        </div>
    </div>
</x-filament-panels::page>
