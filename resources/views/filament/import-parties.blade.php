<x-filament-panels::page>
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
                <li>Data <strong>belum tersimpan</strong> sampai Anda menekan tombol Import di layar pratinjau.</li>
                <li>Kode atau nama yang sudah ada <strong>dilewati</strong>, tidak ditimpa. Beda huruf besar, spasi, atau tanda baca dianggap nama yang sama ("PT. Maju" = "PT Maju").</li>
                <li>Nama yang <strong>mirip</strong> (mis. "PT Maju Jaya" dan "CV Maju Jaya") tetap diimpor tetapi diberi peringatan.</li>
                <li>Tanpa kolom Kode, kode dibuat otomatis ({{ $isVendor ? 'VD-001' : 'KL-001' }}, melanjutkan nomor yang ada).</li>
            </ul>
        </div>
    @else
        {{-- Langkah 2: pratinjau --}}
        @php($s = $preview['summary'])

        <div class="asset-import-summary">
            <div><span class="asset-import-num">{{ $s['import'] }}</span> {{ $label }} akan diimpor</div>
            <div><span class="asset-import-num">{{ $s['similar'] }}</span> nama mirip, periksa</div>
            <div><span class="asset-import-num">{{ $s['skip'] }}</span> dilewati (sudah ada)</div>
            @if ($s['other_type'] || $s['invalid'])
                <div><span class="asset-import-num">{{ $s['other_type'] + $s['invalid'] }}</span> bukan {{ $label }} / tidak valid</div>
            @endif
        </div>

        <p class="muted text-sm">Dibaca dari sheet <strong>{{ $preview['sheet'] }}</strong>.
            @if ($s['other_type'])
                {{ $s['other_type'] }} baris berjenis lain (mis. {{ $isVendor ? 'konsumen / piutang' : 'supplier / hutang' }}, karyawan) tidak ikut.
            @endif
            @if ($s['invalid'])
                {{ $s['invalid'] }} baris berkode lebih dari 20 karakter dilewati.
            @endif
        </p>

        <div class="report-card">
            <div class="overflow-x-auto">
                <table class="report-table asset-import-table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama</th>
                            @if ($isVendor)
                                <th>Jenis Vendor</th>
                            @endif
                            <th>Alamat</th>
                            <th>Telepon</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($preview['rows'] as $r)
                            <tr @class(['asset-import-skip' => $r['skip']])>
                                <td class="mono">
                                    {{ $r['final_code'] ?? $r['code'] ?? '–' }}
                                    @if ($r['code_generated'])
                                        <div class="muted text-xs">otomatis</div>
                                    @endif
                                </td>
                                <td>{{ $r['name'] }}</td>
                                @if ($isVendor)
                                    <td>
                                        {{ $vendorTypes[$r['vendor_type']] ?? $r['vendor_type'] }}
                                        @if ($r['desc'])
                                            <div class="muted text-xs">dari "{{ \Illuminate\Support\Str::limit($r['desc'], 40) }}"</div>
                                        @endif
                                    </td>
                                @endif
                                <td>{{ $r['address'] ?? '–' }}</td>
                                <td class="mono">{{ $r['phone'] ?? '–' }}</td>
                                <td>
                                    @if ($r['skip'])
                                        <span class="report-badge">Dilewati: {{ $r['skip'] }}</span>
                                    @elseif ($r['warning'])
                                        <span class="report-badge report-badge-warning">{{ $r['warning'] }}</span>
                                    @else
                                        <span class="report-badge report-badge-success">Baru</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $isVendor ? 6 : 5 }}" class="muted">Tidak ada baris {{ $label }} di file ini.</td></tr>
                        @endforelse
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
                :disabled="$s['import'] === 0"
            >
                Import {{ $s['import'] }} {{ $label }}
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
