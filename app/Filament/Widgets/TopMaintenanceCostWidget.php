<?php

namespace App\Filament\Widgets;

use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Widget dashboard "Top Aset Maintenance".
 *
 * Menampilkan 5 aset dengan total biaya maintenance tertinggi dalam periode
 * filter dashboard. Membantu controller melihat aset mana yang perlu review
 * (mungkin sudah aus, sering rusak, perlu diganti).
 */
class TopMaintenanceCostWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top Aset Maintenance';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $companyId = $tenant?->getKey();

        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfYear());
        $endDate   = Carbon::parse($this->filters['endDate'] ?? now());

        return $table
            ->query(function () use ($companyId, $startDate, $endDate): Builder {
                // Subquery agregat cost per asset. Filter cost > 0 supaya log
                // service gratis (garansi/inspeksi) tidak dihitung frekuensi —
                // konsisten dengan sort by total_cost & konteks "boros maintenance".
                $costSub = DB::table('asset_maintenance_logs')
                    ->select('asset_id', DB::raw('SUM(cost) as total_cost'), DB::raw('COUNT(*) as log_count'))
                    ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                    ->where('cost', '>', 0)
                    ->whereBetween('maintenance_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->groupBy('asset_id');

                return Asset::query()
                    ->when($companyId, fn ($q) => $q->where('assets.company_id', $companyId))
                    ->joinSub($costSub, 'mnt', 'mnt.asset_id', '=', 'assets.id')
                    ->addSelect('assets.*', 'mnt.total_cost', 'mnt.log_count')
                    ->orderByDesc('mnt.total_cost')
                    ->limit(5);
            })
            ->columns([
                TextColumn::make('asset_code')
                    ->label('Kode')
                    ->badge(),

                TextColumn::make('name')
                    ->label('Nama Aset')
                    ->limit(30),

                TextColumn::make('type')
                    ->label('Jenis')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'dump_truck'            => 'Dump Truck',
                        'excavator'             => 'Excavator',
                        'bulldozer'             => 'Bulldozer',
                        'wheel_loader'          => 'Wheel Loader',
                        'kendaraan_operasional' => 'Kendaraan Op.',
                        'peralatan_kantor'      => 'Peralatan Kantor',
                        default                 => ucwords(str_replace('_', ' ', $state)),
                    })
                    ->badge()
                    ->color('gray'),

                TextColumn::make('log_count')
                    ->label('Frekuensi')
                    ->alignEnd()
                    ->suffix(' kali'),

                TextColumn::make('total_cost')
                    ->label('Total Biaya')
                    ->money('IDR')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger'),
            ])
            ->paginated(false);
    }
}
