{{-- Tombol "Panduan" di topbar (menggantikan global search) — membuka pop-up dari atas. --}}
<x-filament::button
    color="gray"
    size="sm"
    :icon="\Filament\Support\Icons\Heroicon::OutlinedBookOpen"
    class="panduan-topbar-button"
    aria-label="Panduan Pengguna"
    x-data
    x-on:click="$dispatch('panduan-buka', { key: null })"
>
    {{-- span sendiri: di layar sempit teks disembunyikan via CSS, ikon tetap tampil --}}
    <span class="panduan-topbar-label">Panduan</span>
</x-filament::button>
