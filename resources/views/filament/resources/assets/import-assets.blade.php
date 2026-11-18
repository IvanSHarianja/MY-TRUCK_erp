<x-filament-panels::page>
    @php
        $fmt = fn ($n) => $n ? 'Rp ' . number_format($n, 0, ',', '.') : null;
    @endphp

    @if ($preview === null)
        {{-- Langkah 1: unggah --}}
        <div class="report-card asset-import-step">
            <form wire:submit="readFile">
                {{ $this->form }}

                <div class="asset-import-actions">
                    <x-filament::button type="submit" icon="heroicon-o-eye">
                        Baca &amp; Pratinjau
                    </x-filament::button>
                </div>
            </form>

            <ul class="asset-import-rules">
                <li>Harga beli kosong → diisi <strong>Rp 0</strong> dan ditandai <em>Perlu dilengkapi</em>.</li>
                <li>Hanya ada tahun → tanggal beli <strong>1 Juli</strong> tahun tersebut (ditandai perkiraan).</li>
                <li>Penyusutan: <strong>garis lurus 8 tahun</strong> (bisa diubah per aset sebelum ada jurnal penyusutan).</li>
                <li>Keterangan "dijual / terjual" → status <strong>Non-Aktif</strong>.</li>
                <li>Kode atau nopol yang sudah ada <strong>dilewati</strong>, tidak ditimpa.</li>
            </ul>
        </div>
    @else
        {{-- Langkah 2: pratinjau --}}
        @php($s = $preview['summary'])

        <div class="asset-import-summary">
            <div><span class="asset-import-num">{{ $s['import'] }}</span> akan diimpor</div>
            <div><span class="asset-import-num">{{ $s['incomplete'] }}</span> perlu dilengkapi</div>
            <div><span class="asset-import-num">{{ $s['skip'] }}</span> dilewati (sudah ada)</div>
        </div>

        @if ($preview['account_error'])
            <div class="coa-notice coa-notice-block" role="alert">{{ $preview['account_error'] }}</div>
        @endif

        <div class="report-card">
            <div class="overflow-x-auto">
                <table class="report-table asset-import-table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Jenis</th>
                            <th>Nopol</th>
                            <th>Tgl Beli</th>
                            <th class="text-right">Harga Beli</th>
                            <th class="text-right">Akumulasi Awal</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $r)
                            <tr @class(['asset-import-skip' => $r['skip']])>
                                <td class="mono">
                                    {{ $r['code'] }}
                                    @if ($r['code_generated'])
                                        <div class="muted text-xs">otomatis</div>
                                    @endif
                                </td>
                                <td>
                                    {{ $r['name'] }}
                                    <div class="muted text-xs">{{ $r['source'] }}</div>
                                </td>
                                <td>
                                    {{ $r['type_label'] }}
                                    @if ($r['type_guessed'])
                                        <div class="muted text-xs">ditebak dari nama</div>
                                    @endif
                                </td>
                                <td class="mono">{{ $r['plate'] ?? '–' }}</td>
                                <td>
                                    @if (in_array('tanggal beli', $r['missing'], true))
                                        <span class="muted">–</span>
                                    @else
                                        {{ \Illuminate\Support\Carbon::parse($r['purchase_date'])->translatedFormat('d M Y') }}
                                        @if ($r['date_estimated'])
                                            <div class="muted text-xs">perkiraan</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="text-right mono">{{ $fmt($r['price']) ?? '–' }}</td>
                                <td class="text-right mono">
                                    {{ $fmt($r['akumulasi_awal'] ?? 0) ?? '–' }}
                                    @if (($r['akumulasi_awal'] ?? 0) > 0 && $r['price'] && $r['akumulasi_awal'] >= $r['price'])
                                        <div class="muted text-xs">sudah habis disusutkan</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($r['skip'])
                                        <span class="report-badge">Dilewati: {{ $r['skip'] }}</span>
                                    @else
                                        @if ($r['status'] === 'non_aktif')
                                            <span class="report-badge">Non-Aktif (dijual)</span>
                                        @endif
                                        @if ($r['missing'])
                                            <span class="report-badge report-badge-warning">Kurang: {{ implode(', ', $r['missing']) }}</span>
                                        @endif
                                        @if (! $r['missing'] && $r['status'] === 'aktif')
                                            <span class="report-badge report-badge-success">Lengkap</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="asset-import-actions">
            <x-filament::button color="gray" wire:click="cancel">
                Ganti file
            </x-filament::button>
            <x-filament::button
                wire:click="import"
                wire:loading.attr="disabled"
                icon="heroicon-o-arrow-down-tray"
                :disabled="$s['import'] === 0 || (bool) $preview['account_error']"
            >
                Import {{ $s['import'] }} aset
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
