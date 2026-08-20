<?php

namespace App\Filament\Widgets;

use App\Services\OperationalInsightService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class CriticalStockWidget extends Widget
{
    protected string $view = 'filament.widgets.critical-stock';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) return ['rows' => []];

        return [
            'rows' => app(OperationalInsightService::class)->criticalStock($tenant->getKey()),
        ];
    }
}
