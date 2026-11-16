<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Perubahan kontrak (tarif, biaya standar, klien, status) yang diajukan dari halaman Edit Kontrak.
            Kontrak tetap memakai nilai lama sampai pengajuan <strong>disetujui</strong>. Setelah disetujui, nilai baru
            berlaku untuk log/transaksi baru. Log yang sudah ada tidak dihitung ulang.
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
