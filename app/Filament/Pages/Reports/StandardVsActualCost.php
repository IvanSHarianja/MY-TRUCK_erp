<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\RequiresReportPermission;
use App\Services\Accounting\StandardVsActualCostService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Biaya operasional per unit: standar kontrak (dari log ritase / jam kerja)
 * dibanding biaya aktual yang dibayar keuangan. Lihat StandardVsActualCostService.
 */
class StandardVsActualCost extends Page implements HasForms
{
    use InteractsWithForms;
    use RequiresReportPermission;

    protected string $view = 'filament.pages.reports.standard-vs-actual-cost';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Biaya Standar vs Aktual';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Biaya Standar vs Aktual per Unit';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['year' => (int) now()->year, 'month' => (int) now()->month]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(4)
            ->components([
                Select::make('year')
                    ->label('Tahun')
                    ->options(collect(range(2020, (int) now()->year + 1))->mapWithKeys(fn ($y) => [$y => (string) $y]))
                    ->required()
                    ->live(),
                Select::make('month')
                    ->label('Bulan')
                    ->options([
                        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                    ])
                    ->required()
                    ->live(),
            ]);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $year   = (int) ($this->data['year'] ?? now()->year);
        $month  = (int) ($this->data['month'] ?? now()->month);

        return [
            'companyName' => $tenant->name,
            'periode'     => \Illuminate\Support\Carbon::create($year, $month, 1)->translatedFormat('F Y'),
            'report'      => app(StandardVsActualCostService::class)->build($tenant->getKey(), $year, $month),
            'components'  => StandardVsActualCostService::COMPONENTS,
        ];
    }
}
