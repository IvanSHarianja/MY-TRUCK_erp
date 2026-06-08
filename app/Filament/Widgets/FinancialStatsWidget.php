<?php

namespace App\Filament\Widgets;

use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashFlowService;
use App\Services\Accounting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class FinancialStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Ringkasan Keuangan';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return [];
        }

        $companyId = $tenant->getKey();

        // Parse filter tanggal
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfYear());
        $endDate   = Carbon::parse($this->filters['endDate'] ?? now());

        $endYear  = $endDate->year;
        $endMonth = $endDate->month;

        // "Sebelum start" = bulan sebelum startDate (untuk hitung delta periode)
        $beforeStart      = $startDate->copy()->startOfMonth()->subMonth();
        $beforeStartYear  = $beforeStart->year;
        $beforeStartMonth = $beforeStart->month;

        $is = app(IncomeStatementService::class);
        $bs = app(BalanceSheetService::class);
        $cf = app(CashFlowService::class);

        // Laba Rugi: delta antara kumulatif s.d akhir dan kumulatif s.d sebelum awal
        $endReport   = $is->getReport($companyId, $endYear, $endMonth);
        $startReport = $is->getReport($companyId, $beforeStartYear, $beforeStartMonth);

        $pendapatan = $endReport['totalPendapatan'] - $startReport['totalPendapatan'];
        $laba       = $endReport['labaBersih'] - $startReport['labaBersih'];
        $margin     = $pendapatan > 0 ? round($laba / $pendapatan * 100, 2) : 0.0;

        // Neraca & Kas: posisi per tanggal akhir (point-in-time)
        $neraca   = $bs->getReport($companyId, $endYear, $endMonth);
        $saldoKas = $cf->getSaldoAkhir($companyId, $endYear, $endMonth);

        $totalAset = $neraca['totalAset'];
        $totalKwjb = $neraca['totalKewajiban'];
        $totalEkui = $neraca['totalEkuitas'];
        $der       = $totalEkui > 0 ? round($totalKwjb / $totalEkui, 2) : 0;

        // Label periode
        $periodLabel = $startDate->translatedFormat('d M Y') . ' - ' . $endDate->translatedFormat('d M Y');
        $posisiLabel = 'Per ' . $endDate->translatedFormat('d M Y');

        $fmt = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');

        return [
            Stat::make('Total Pendapatan', $fmt($pendapatan))
                ->description($periodLabel)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Laba Bersih', $fmt($laba))
                ->description($laba >= 0 ? "Profit | {$periodLabel}" : "Loss | {$periodLabel}")
                ->descriptionIcon($laba >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($laba >= 0 ? 'success' : 'danger'),

            Stat::make('Margin Laba', $margin . '%')
                ->description('Rasio laba bersih')
                ->descriptionIcon('heroicon-m-chart-pie')
                ->color($margin >= 30 ? 'success' : ($margin >= 10 ? 'warning' : 'danger')),

            Stat::make('Saldo Kas', $fmt($saldoKas))
                ->description($posisiLabel)
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($saldoKas > 0 ? 'info' : 'danger'),

            Stat::make('Total Aset', $fmt($totalAset))
                ->description($posisiLabel)
                ->descriptionIcon('heroicon-m-building-library')
                ->color('info'),

            Stat::make('Total Kewajiban', $fmt($totalKwjb))
                ->description($posisiLabel)
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('warning'),

            Stat::make('Total Ekuitas', $fmt($totalEkui))
                ->description($posisiLabel)
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success'),

            Stat::make('DER (Kewajiban/Ekuitas)', $der . 'x')
                ->description($der < 0.5 ? 'Sehat' : ($der < 1 ? 'Hati-hati' : 'Berisiko'))
                ->descriptionIcon('heroicon-m-scale')
                ->color($der < 0.5 ? 'success' : ($der < 1 ? 'warning' : 'danger')),
        ];
    }
}
