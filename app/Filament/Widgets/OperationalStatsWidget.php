<?php

namespace App\Filament\Widgets;

use App\Services\OperationalInsightService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationalStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) return [];

        $data = app(OperationalInsightService::class)->stats($tenant->getKey());

        $jamTrend = $this->trend($data['jam_bulan_ini'], $data['jam_bulan_lalu']);
        $ritTrend = $this->trend($data['rit_bulan_ini'], $data['rit_bulan_lalu']);

        return [
            Stat::make('Jam Kerja Bulan Ini',
                    number_format($data['jam_bulan_ini'], 2, ',', '.') . ' jam')
                ->description($jamTrend['label'] . ' vs bulan lalu')
                ->descriptionIcon($jamTrend['icon'])
                ->color($jamTrend['color']),

            Stat::make('Total Ritase Bulan Ini',
                    number_format($data['rit_bulan_ini'], 0, ',', '.') . ' rit')
                ->description($ritTrend['label'] . ' vs bulan lalu')
                ->descriptionIcon($ritTrend['icon'])
                ->color($ritTrend['color']),

            Stat::make('Potensi Belum Ditagih',
                    'Rp ' . number_format($data['unbilled_potential'], 0, ',', '.'))
                ->description($data['unbilled_potential'] > 0
                    ? 'Log siap ditagih — segera issue invoice'
                    : 'Semua log sudah ditagih')
                ->descriptionIcon($data['unbilled_potential'] > 0 ? 'heroicon-o-exclamation-circle' : 'heroicon-o-check-circle')
                ->color($data['unbilled_potential'] > 0 ? 'warning' : 'success'),

            Stat::make('Aset Aktif / Idle',
                    "{$data['aset_aktif']} aktif · {$data['aset_idle']} idle")
                ->description($data['aset_idle'] > 0
                    ? $data['aset_idle'] . ' aset tidak ada log >7 hari'
                    : 'Semua aset aktif')
                ->descriptionIcon($data['aset_idle'] > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($data['aset_idle'] > 2 ? 'danger' : ($data['aset_idle'] > 0 ? 'warning' : 'success')),
        ];
    }

    /**
     * @return array{label: string, icon: string, color: string}
     */
    private function trend(float $current, float $previous): array
    {
        if ($previous <= 0) {
            return ['label' => 'Baru mulai', 'icon' => 'heroicon-o-sparkles', 'color' => 'info'];
        }

        $pct = round((($current - $previous) / $previous) * 100, 1);

        if ($pct > 5) {
            return ['label' => '+' . $pct . '%', 'icon' => 'heroicon-o-arrow-trending-up', 'color' => 'success'];
        }
        if ($pct < -5) {
            return ['label' => $pct . '%', 'icon' => 'heroicon-o-arrow-trending-down', 'color' => 'danger'];
        }
        return ['label' => ($pct >= 0 ? '+' : '') . $pct . '%', 'icon' => 'heroicon-o-minus', 'color' => 'gray'];
    }
}
