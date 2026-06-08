<?php

namespace App\Filament\Widgets;

use App\Services\Accounting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

class MonthlyProfitTrendWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Tren Laba Bersih per Bulan';

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

        $companyId = $tenant->getKey();

        $is = app(IncomeStatementService::class);

        // Parse filter tanggal
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->subMonths(11)->startOfMonth());
        $endDate   = Carbon::parse($this->filters['endDate'] ?? now());

        $labels     = [];
        $pendapatan = [];
        $laba       = [];

        // Loop per bulan dalam rentang tanggal yang dipilih
        $cursor    = $startDate->copy()->startOfMonth();
        $endOfLoop = $endDate->copy()->startOfMonth();

        while ($cursor->lte($endOfLoop)) {
            $labels[] = $cursor->translatedFormat('M Y');

            // Hitung L/R untuk bulan ini saja (delta kumulatif)
            $thisMonth = $is->getReport($companyId, $cursor->year, $cursor->month);

            $prev      = $cursor->copy()->subMonth();
            $prevMonth = $is->getReport($companyId, $prev->year, $prev->month);

            $monthRevenue = $thisMonth['totalPendapatan'] - $prevMonth['totalPendapatan'];
            $monthLaba    = $thisMonth['labaBersih'] - $prevMonth['labaBersih'];

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
