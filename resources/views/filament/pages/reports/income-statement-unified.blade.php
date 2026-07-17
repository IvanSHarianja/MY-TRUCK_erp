<x-filament-panels::page>
    <div class="report-filters">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>

    {{-- Tab Navigation --}}
    <div style="display:flex;gap:4px;border-bottom:2px solid rgba(127,127,127,0.15);margin-bottom:16px;overflow-x:auto;">
        @php
            $tabs = [
                'ringkasan' => ['label' => 'Ringkasan', 'desc' => 'Total company'],
                'per_lini'  => ['label' => 'Per Lini Bisnis', 'desc' => 'Segmentasi RENT/ARMD/MATL/BONG'],
                'per_unit'  => ['label' => 'Per Unit (Aset)', 'desc' => 'Cost tracking per aset'],
            ];
        @endphp

        @foreach ($tabs as $tabKey => $tab)
            @php $isActive = $activeTab === $tabKey; @endphp
            <button
                type="button"
                wire:click="setActiveTab('{{ $tabKey }}')"
                style="
                    padding: 10px 20px;
                    border: none;
                    background: {{ $isActive ? 'var(--mt-accent-blue, #2563eb)' : 'transparent' }};
                    color: {{ $isActive ? 'white' : 'var(--mt-text, inherit)' }};
                    font-weight: {{ $isActive ? '600' : '500' }};
                    font-size: 14px;
                    cursor: pointer;
                    border-radius: 6px 6px 0 0;
                    border-bottom: {{ $isActive ? '2px solid var(--mt-accent-blue, #2563eb)' : '2px solid transparent' }};
                    margin-bottom: -2px;
                    transition: background 0.15s;
                    white-space: nowrap;
                "
                title="{{ $tab['desc'] }}"
            >
                {{ $tab['label'] }}
            </button>
        @endforeach
    </div>

    {{-- Content per Tab --}}
    @if ($activeTab === 'ringkasan')
        @include('filament.pages.reports.partials._ringkasan')
    @elseif ($activeTab === 'per_lini')
        @include('filament.pages.reports.partials._per-lini')
    @elseif ($activeTab === 'per_unit')
        @include('filament.pages.reports.partials._per-unit')
    @endif
</x-filament-panels::page>
