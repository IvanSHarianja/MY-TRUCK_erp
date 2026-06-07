<?php

namespace App\Filament\Widgets;

use App\Models\BusinessUnit;
use App\Services\Accounting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class RevenueByBusinessUnitWidget extends ChartWidget
{
    protected ?string $heading = 'Pendapatan per Lini Bisnis';

    protected ?string $description = 'YTD tahun fiscal aktif';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md'      => 1,
    ];

    protected ?string $maxHeight = '260px';

    public ?string $filter = 'ytd';

    protected function getFilters(): ?array
    {
        return [
            'ytd'     => 'Year-to-Date',
            'last_3'  => '3 Bulan Terakhir',
            'last_12' => '12 Bulan Terakhir',
        ];
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return ['datasets' => [], 'labels' => []];
        }

        $year = (int) ($tenant->fiscal_year ?? now()->year);

        $units = BusinessUnit::where('company_id', $tenant->getKey())
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $is = app(IncomeStatementService::class);

        $labels   = [];
        $data     = [];
        $colors   = [];

        foreach ($units as $unit) {
            $labels[] = $unit->name;

            $revenue = $is->getTotalRevenue($tenant->getKey(), $year, (int) now()->month, $unit->id);
            $data[]  = (float) $revenue;
            $colors[] = $unit->color;
        }

        // Tambah revenue tanpa lini (business_unit_id NULL)
        $allRevenue = $is->getTotalRevenue($tenant->getKey(), $year, (int) now()->month);
        $unitsSum   = array_sum($data);
        $tanpaLini  = $allRevenue - $unitsSum;

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
