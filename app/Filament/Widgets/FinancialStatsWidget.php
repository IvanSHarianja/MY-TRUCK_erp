<?php

namespace App\Filament\Widgets;

use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashFlowService;
use App\Services\Accounting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Ringkasan Keuangan';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if (!$tenant) {
            return [];
        }

        $year = (int) ($tenant->fiscal_year ?? now()->year);
        $month = (int) now()->month;

        $is = app(IncomeStatementService::class);
        $bs = app(BalanceSheetService::class);
        $cf = app(CashFlowService::class);

        $report = $is->getReport($tenant->getKey(), $year, $month);
        $neraca = $bs->getReport($tenant->getKey(), $year, $month);
        $saldoKas = $cf->getSaldoAkhir($tenant->getKey(), $year, $month);

        $pendapatan = $report['totalPendapatan'];
        $laba = $report['labaBersih'];
        $margin = $report['marginLaba'];
        $totalAset = $neraca['totalAset'];
        $totalKwjb = $neraca['totalKewajiban'];
        $totalEkui = $neraca['totalEkuitas'];

        $der = $totalEkui > 0 ? round($totalKwjb / $totalEkui, 2) : 0;

        $fmt = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');

        return [
            Stat::make('Total Pendapatan', $fmt($pendapatan))
                ->description("YTD {$year}")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Laba Bersih', $fmt($laba))
                ->description($laba >= 0 ? "Profit YTD {$year}" : "Loss YTD {$year}")
                ->descriptionIcon($laba >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($laba >= 0 ? 'success' : 'danger'),

            Stat::make('Margin Laba', $margin . '%')
                ->description('Rasio laba bersih')
                ->descriptionIcon('heroicon-m-chart-pie')
                ->color($margin >= 30 ? 'success' : ($margin >= 10 ? 'warning' : 'danger')),

            Stat::make('Saldo Kas', $fmt($saldoKas))
                ->description('Kas & Bank + Kas Kecil')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($saldoKas > 0 ? 'info' : 'danger'),

            Stat::make('Total Aset', $fmt($totalAset))
                ->description('Aset Lancar + Aset Tetap')
                ->descriptionIcon('heroicon-m-building-library')
                ->color('info'),

            Stat::make('Total Kewajiban', $fmt($totalKwjb))
                ->description('Lancar + Jangka Panjang')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('warning'),

            Stat::make('Total Ekuitas', $fmt($totalEkui))
                ->description('Modal + Laba Berjalan')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success'),

            Stat::make('DER (Kewajiban/Ekuitas)', $der . 'x')
                ->description($der < 0.5 ? 'Sehat' : ($der < 1 ? 'Hati-hati' : 'Berisiko'))
                ->descriptionIcon('heroicon-m-scale')
                ->color($der < 0.5 ? 'success' : ($der < 1 ? 'warning' : 'danger')),
        ];
    }
}
