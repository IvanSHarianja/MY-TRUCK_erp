<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Unduh file SOP</x-slot>
        <x-slot name="description">
            Salinan data MY-TRUCK dalam format file SOP Armada. Pilih file, periode, lalu unduh — satu file per unduhan.
        </x-slot>

        <form wire:submit="download" class="space-y-6">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray">
                    Unduh Backup Excel
                </x-filament::button>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    File disiapkan 20 detik – 1,5 menit (Finance paling lama) — tunggu sampai unduhan muncul di browser.
                </span>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">Isi tiap file</x-slot>

        <ul class="list-disc space-y-1 ps-5 text-sm">
            @foreach (\App\Services\Export\SopFile::cases() as $file)
                <li><strong>{{ $file->label() }}</strong> — {{ $file->description() }}</li>
            @endforeach
        </ul>

        <ul class="mt-4 list-disc space-y-1 ps-5 text-sm text-gray-500 dark:text-gray-400">
            <li>Angka & nomor dokumen sama dengan MY-TRUCK. Data sebelum tanggal DARI diringkas (saldo awal, akumulasi penyusutan, stok awal).</li>
            <li>Sheet yang di template menarik data dari file lain (IMPORTRANGE) diisi salinan statis, jadi file bisa dibuka sendiri tanpa internet.</li>
            <li>Bila jumlah data melebihi kapasitas template, pilih periode yang lebih pendek.</li>
            <li>File ini salinan data, bukan pengganti backup database.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
