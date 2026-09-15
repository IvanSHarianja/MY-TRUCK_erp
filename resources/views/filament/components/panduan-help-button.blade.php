{{-- Ikon "?" di header tiap halaman → buka pop-up Panduan langsung di bagian halaman ini. --}}
<x-filament::icon-button
    color="gray"
    :icon="\Filament\Support\Icons\Heroicon::OutlinedQuestionMarkCircle"
    :label="'Panduan: ' . $title"
    :tooltip="'Panduan: ' . $title"
    class="panduan-help-button"
    data-panduan-key="{{ $key }}"
    x-data
    x-on:click="$dispatch('panduan-buka', { key: $el.dataset.panduanKey })"
/>
