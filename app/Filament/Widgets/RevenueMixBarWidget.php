<?php

namespace App\Filament\Widgets;

use App\Models\BusinessUnit;
use App\Services\Accounting\IncomeStatementMatrixService;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class RevenueMixBarWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.revenue-mix-bar';

    protected int|string|array $columnSpan = 'full';

    /** Auto-refresh tiap 5 detik */
    protected ?string $pollingInterval = '5s';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return ['segments' => [], 'totalRevenue' => 0, 'periodLabel' => ''];
        }

        $endDate = Carbon::parse($this->filters['endDate'] ?? now());
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfYear());

        $svc = app(IncomeStatementMatrixService::class);

        $endReport = $svc->getReport($tenant->getKey(), $endDate->year, $endDate->month);

        $beforeStart = $startDate->copy()->startOfMonth()->subMonth();
        $startReport = $svc->getReport($tenant->getKey(), $beforeStart->year, $beforeStart->month);

        // Delta = end - sebelum_start (untuk hanya menampilkan revenue di range filter)
        $businessUnits = $endReport['businessUnits'];
        $segments = [];
        $totalRevenue = 0;

        foreach ($businessUnits as $bu) {
            $endVal   = (float) ($endReport['revenuePerLini'][$bu->id] ?? 0);
            $startVal = (float) ($startReport['revenuePerLini'][$bu->id] ?? 0);
            $delta    = max(0, $endVal - $startVal);

            if ($delta > 0) {
                $segments[] = [
                    'id'      => $bu->id,
                    'code'    => $bu->code,
                    'name'    => $bu->name,
                    'color'   => $bu->color,
                    'amount'  => $delta,
                ];
                $totalRevenue += $delta;
            }
        }

        // Tanpa Lini
        $tanpaLiniId = $endReport['tanpaLiniId'];
        $endTanpaLini = (float) ($endReport['revenuePerLini'][$tanpaLiniId] ?? 0);
        $startTanpaLini = (float) ($startReport['revenuePerLini'][$tanpaLiniId] ?? 0);
        $deltaTanpaLini = max(0, $endTanpaLini - $startTanpaLini);

        if ($deltaTanpaLini > 0) {
            $segments[] = [
                'id'      => 0,
                'code'    => 'NONE',
                'name'    => '(Tanpa Lini)',
                'color'   => '#94A3B8',
                'amount'  => $deltaTanpaLini,
            ];
            $totalRevenue += $deltaTanpaLini;
        }

        // Sort by amount DESC
        usort($segments, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        // Calc percentage
        foreach ($segments as &$seg) {
            $seg['percentage'] = $totalRevenue > 0
                ? round($seg['amount'] / $totalRevenue * 100, 1)
                : 0;
        }

        return [
            'segments'     => $segments,
            'totalRevenue' => $totalRevenue,
            'periodLabel'  => $startDate->translatedFormat('d M Y') . ' — ' . $endDate->translatedFormat('d M Y'),
        ];
    }
}
