<x-filament-panels::page>
    <div class="space-y-6">

        {{-- ============== FORM INPUT ============== --}}
        <form wire:submit.prevent="submit">
            {{ $this->form }}

            <div class="mt-4 flex justify-end gap-2">
                <x-filament::button
                    type="submit"
                    color="primary"
                    icon="heroicon-o-banknotes"
                >
                    Simpan &amp; Posting Jurnal
                </x-filament::button>
            </div>
        </form>

        {{-- ============== TABEL TRANSAKSI ============== --}}
        <div>
            <h2 class="text-base font-semibold mb-3" style="color: var(--mt-text-primary, inherit);">
                Riwayat Transaksi Cepat
            </h2>
            {{ $this->table }}
        </div>

    </div>
</x-filament-panels::page>
