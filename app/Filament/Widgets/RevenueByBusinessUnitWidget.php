<?php

namespace App\Filament\Widgets;

use App\Models\BusinessUnit;
use App\Services\Accounting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

class RevenueByBusinessUnitWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Pendapatan per Lini Bisnis';

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

        // Parse filter tanggal
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfYear());
        $endDate   = Carbon::parse($this->filters['endDate'] ?? now());

        $endYear  = $endDate->year;
        $endMonth = $endDate->month;

        $beforeStart      = $startDate->copy()->startOfMonth()->subMonth();
        $beforeStartYear  = $beforeStart->year;
        $beforeStartMonth = $beforeStart->month;

        $units = BusinessUnit::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $is = app(IncomeStatementService::class);

        $labels = [];
        $data   = [];
        $colors = [];

        foreach ($units as $unit) {
            $labels[] = $unit->name;

            $endRevenue   = $is->getTotalRevenue($companyId, $endYear, $endMonth, $unit->id);
            $startRevenue = $is->getTotalRevenue($companyId, $beforeStartYear, $beforeStartMonth, $unit->id);
            $revenue      = $endRevenue - $startRevenue;

            $data[]   = (float) max(0, $revenue);
            $colors[] = $unit->color;
        }

        // Tambah revenue tanpa lini (business_unit_id NULL)
        $allEndRevenue   = $is->getTotalRevenue($companyId, $endYear, $endMonth);
        $allStartRevenue = $is->getTotalRevenue($companyId, $beforeStartYear, $beforeStartMonth);
        $allRevenue      = $allEndRevenue - $allStartRevenue;
        $unitsSum        = array_sum($data);
        $tanpaLini       = $allRevenue - $unitsSum;

        if ($tanpaLini > 0.01) {
            $labels[] = '(Tanpa Lini)';
            $data[]   = $tanpaLini;
            $colors[] = '#94A3B8';
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Pendapatan (Rp)',
                    'data'            => $data,
                    'backgroundColor' => $colors,
                    'borderColor'     => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'ticks' => [
                        'callback' => 'function(value) { return "Rp " + value.toLocaleString("id-ID"); }',
                    ],
                ],
            ],
        ];
    }
}
