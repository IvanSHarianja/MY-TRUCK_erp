<?php

namespace App\Filament\Widgets;

use App\Services\OperationalInsightService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class UnbilledLogsWidget extends Widget
{
    protected string $view = 'filament.widgets.unbilled-logs';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) return ['rows' => []];

        return [
            'rows' => app(OperationalInsightService::class)->unbilledLogs($tenant->getKey()),
        ];
    }
}
