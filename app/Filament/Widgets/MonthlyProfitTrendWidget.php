<?php

namespace App\Filament\Widgets;

use App\Services\Accounting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class MonthlyProfitTrendWidget extends ChartWidget
{
    protected ?string $heading = 'Tren Laba Bersih per Bulan';

    protected ?string $description = '12 bulan terakhir';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md'      => 1,
    ];

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return ['datasets' => [], 'labels' => []];
        }

        $is = app(IncomeStatementService::class);

        $labels      = [];
        $pendapatan  = [];
        $laba        = [];

        // 12 bulan terakhir (sampai bulan ini)
        $cursor = Carbon::now()->startOfMonth()->subMonths(11);

        for ($i = 0; $i < 12; $i++) {
            $labels[] = $cursor->translatedFormat('M Y');

            // Hitung L/R untuk bulan tersebut saja (delta antara cumulative s.d bulan ini dan s.d bulan lalu)
            $thisMonth = $is->getReport($tenant->getKey(), $cursor->year, $cursor->month);

            $prev = $cursor->copy()->subMonth();
            $prevMonth = $is->getReport($tenant->getKey(), $prev->year, $prev->month);

            $monthRevenue = $thisMonth['totalPendapatan'] - $prevMonth['totalPendapatan'];
            $monthLaba    = $thisMonth['labaBersih']    - $prevMonth['labaBersih'];

            $pendapatan[] = (float) max(0, $monthRevenue);
            $laba[]       = (float) $monthLaba;

            $cursor->addMonth();
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Pendapatan',
                    'data'            => $pendapatan,
                    'borderColor'     => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill'            => true,
                    'tension'         => 0.3,
                ],
                [
                    'label'           => 'Laba Bersih',
                    'data'            => $laba,
                    'borderColor'     => '#3B82F6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill'            => true,
                    'tension'         => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top'],
            ],
            'scales' => [
                'y' => [
                    'ticks' => [
                        'callback' => 'function(value) { return "Rp " + (value/1000000).toFixed(1) + "M"; }',
                    ],
                ],
            ],
        ];
    }
}
