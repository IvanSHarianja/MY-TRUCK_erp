<?php

namespace App\Filament\Widgets;

use App\Services\OperationalInsightService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class UtilizationRateWidget extends Widget
{
    protected string $view = 'filament.widgets.utilization-rate';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) return ['rows' => []];

        return [
            'rows' => app(OperationalInsightService::class)->utilizationRate($tenant->getKey()),
        ];
    }
}
