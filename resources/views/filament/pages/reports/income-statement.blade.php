<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    @include('filament.pages.reports.partials._ringkasan')
</x-filament-panels::page>
