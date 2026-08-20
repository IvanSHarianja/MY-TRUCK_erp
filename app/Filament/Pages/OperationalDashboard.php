<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CriticalStockWidget;
use App\Filament\Widgets\OperationalStatsWidget;
use App\Filament\Widgets\UnbilledLogsWidget;
use App\Filament\Widgets\UtilizationRateWidget;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Dashboard Operasional — insight potensi & tindakan untuk owner/manajer.
 * Fokus: utilization aset, unbilled potential, stok kritis (bukan angka pasif).
 */
class OperationalDashboard extends Page
{
    protected string $view = 'filament.pages.operational-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Dashboard Operasional';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 0; // paling atas di grup Operasional

    protected static ?string $title = 'Dashboard Operasional';

    public static function canAccess(): bool
    {
        return auth()->check() && Filament::getTenant() !== null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OperationalStatsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }

    protected function getFooterWidgets(): array
    {
        return [
            UtilizationRateWidget::class,
            UnbilledLogsWidget::class,
            CriticalStockWidget::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
