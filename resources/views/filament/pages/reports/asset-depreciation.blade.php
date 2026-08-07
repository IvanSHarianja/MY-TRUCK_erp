@use('App\Enums\DepreciationMethod')
@use('App\Services\Accounting\AssetDepreciationReportService')

<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @php
        $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : 'Rp ' . number_format((float) $n, 0, ',', '.');
        $rows = $rows ?? [];
        $totals = $totals ?? ['purchase_price' => 0, 'akumulasi' => 0, 'nilai_buku' => 0, 'next_month_dep' => 0];

        $methodLabelFilter = null;
        if (! empty($methodFilter)) {
            $methodLabelFilter = DepreciationMethod::from($methodFilter)->label();
        }
    @endphp

    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">{{ $companyName }}</div>
            <div class="report-header-subtitle">
                Laporan Penyusutan per Aset<br>
                <span style="font-weight: 400; font-size: 12px;">
                    {{ $period_label }} · as of {{ \Carbon\Carbon::parse($as_of)->translatedFormat('d F Y') }}
                    @if ($typeFilter ?? null)
                        · Jenis: {{ AssetDepreciationReportService::typeLabel($typeFilter) }}
                    @endif
                    @if ($methodLabelFilter)
                        · Metode: {{ $methodLabelFilter }}
                    @endif
                </span>
            </div>
        </div>

        @if (empty($rows))
            <div style="padding: 32px; text-align: center; color: var(--mt-text-muted, #6b7280);">
                Belum ada aset dengan filter ini.
                @if ($typeFilter || $methodLabelFilter)
                    <br><span style="font-size: 12px;">Coba lepas filter atau ganti periode.</span>
                @endif
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="report-table" style="min-width: 1100px;">
                    <thead>
                        <tr>
                            <th style="width: 18%;">Aset</th>
                            <th style="width: 10%;">Jenis</th>
                            <th style="width: 12%;">Metode</th>
                            <th style="width: 9%;">Tgl Beli</th>
                            <th class="text-right" style="width: 11%;">Harga Beli</th>
                            <th class="text-right" style="width: 11%;">Akumulasi</th>
                            <th class="text-right" style="width: 11%;">Nilai Buku</th>
                            <th class="text-right" style="width: 8%;">Sisa Umur</th>
                            <th class="text-right" style="width: 10%;">Est. Bln Depan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr @if ($row['fully_depreciated']) style="background: rgba(251, 191, 36, 0.10);" @endif>
                                <td>
                                    <div style="font-weight: 600;">[{{ $row['asset_code'] }}]</div>
                                    <div style="font-size: 12px; opacity: 0.75;">
                                        {{ $row['name'] }}
                                        @if ($row['status'] === 'non_aktif')
                                            <span style="color: var(--mt-accent-red, #dc2626); font-weight: 600;">· non-aktif</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span style="font-size: 12px;">{{ AssetDepreciationReportService::typeLabel($row['type']) }}</span>
                                </td>
                                <td>
                                    <span style="font-size: 12px;">{{ $row['method_label'] }}</span>
                                </td>
                                <td>
                                    <span style="font-size: 12px; opacity: 0.75;">
                                        {{ $row['purchase_date'] ? \Carbon\Carbon::parse($row['purchase_date'])->format('d-m-Y') : '–' }}
                                    </span>
                                </td>
                                <td class="text-right mono">{{ $fmt($row['purchase_price']) }}</td>
                                <td class="text-right mono negative">{{ $fmt($row['akumulasi']) }}</td>
                                <td class="text-right mono" style="font-weight: 600;">{{ $fmt($row['nilai_buku']) }}</td>
                                <td class="text-right mono" style="font-size: 12px;">
                                    {{ number_format((float) $row['sisa_umur'], (float) $row['sisa_umur'] == (int) $row['sisa_umur'] ? 0 : 2, ',', '.') }}
                                    <span class="muted" style="font-size: 11px;">{{ $row['sisa_umur_unit'] }}</span>
                                </td>
                                <td class="text-right mono" style="font-size: 12px;">
                                    @if ($row['method'] === 'straight_line')
                                        {{ $fmt($row['next_month_dep']) }}
                                    @else
                                        <span class="muted" style="font-size: 11px; font-style: italic;">tergantung usage</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        <tr style="background: rgba(127, 127, 127, 0.08); font-weight: 700; border-top: 2px solid rgba(127, 127, 127, 0.3);">
                            <td colspan="4">TOTAL</td>
                            <td class="text-right mono">{{ $fmt($totals['purchase_price']) }}</td>
                            <td class="text-right mono">{{ $fmt($totals['akumulasi']) }}</td>
                            <td class="text-right mono">{{ $fmt($totals['nilai_buku']) }}</td>
                            <td></td>
                            <td class="text-right mono">{{ $fmt($totals['next_month_dep']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px; padding: 12px; background: rgba(59, 130, 246, 0.08); border-radius: 6px; font-size: 12px; line-height: 1.6;">
                <strong>Cara Baca:</strong>
                <ul style="margin: 6px 0 0 18px; padding: 0;">
                    <li><strong>Akumulasi</strong> diambil dari ledger (jurnal DEP-* / DEPUSE-* status posted, sudah net-off pembalik void) — <em>konsisten dengan Akumulasi Penyusutan di Neraca per tanggal sama</em>.</li>
                    <li>Baris <span style="background: rgba(251, 191, 36, 0.2); padding: 1px 6px; border-radius: 3px;">kuning</span> = aset sudah fully depreciated (nilai buku ≤ residu).</li>
                    <li>Kolom <strong>Est. Bulan Depan</strong> hanya berlaku untuk metode Garis Lurus. Aset usage-based (per jam/rit/hari) tergantung pemakaian aktual — akan otomatis nge-post saat log usage di-input (setelah BIZ-03 aktif).</li>
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
