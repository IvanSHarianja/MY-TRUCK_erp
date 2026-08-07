<?php

namespace App\Filament\Pages\Reports;

use App\Models\BusinessUnit;
use App\Services\Accounting\AssetCostPerUnitService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * BIZ-05 — Laporan Biaya Operasional per Unit.
 * Rugi/untung per aset per jam/rit. Sort default margin ascending (rugi di atas).
 */
class AssetCostPerUnit extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.asset-cost-per-unit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Biaya per Unit';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Laporan Biaya Operasional per Unit';

    public ?array $data = [];

    public int $year;
    public int $month;
    public ?string $typeFilter = null;
    public ?int $businessUnitId = null;
    public bool $onlyLosing = false;

    public function mount(): void
    {
        $this->year  = (int) now()->year;
        $this->month = (int) now()->month;

        $this->form->fill([
            'year'             => $this->year,
            'month'            => $this->month,
            'type_filter'      => null,
            'business_unit_id' => null,
            'only_losing'      => false,
        ]);
    }

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();

        return [
            Action::make('export_pdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => route('pdf.asset-cost-per-unit', [
                    'tenant'           => $tenant->slug,
                    'year'             => $this->year,
                    'month'            => $this->month,
                    'type'             => $this->typeFilter,
                    'business_unit_id' => $this->businessUnitId,
                    'only_losing'      => $this->onlyLosing ? 1 : 0,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $tenant = Filament::getTenant();

        return $schema
            ->statePath('data')
            ->columns(5)
            ->components([
                Select::make('year')
                    ->label('Tahun')
                    ->options(collect(range(2020, (int) now()->year + 1))
                        ->mapWithKeys(fn ($y) => [$y => (string) $y]))
                    ->default(now()->year)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->year = (int) $state),

                Select::make('month')
                    ->label('Bulan')
                    ->options([
                        1  => 'Januari',  2  => 'Februari', 3  => 'Maret',    4  => 'April',
                        5  => 'Mei',      6  => 'Juni',     7  => 'Juli',     8  => 'Agustus',
                        9  => 'September',10 => 'Oktober',  11 => 'November', 12 => 'Desember',
                    ])
                    ->default((int) now()->month)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->month = (int) $state),

                Select::make('type_filter')
                    ->label('Jenis Aset')
                    ->options([
                        null                    => 'Semua jenis',
                        'dump_truck'            => 'Dump Truck',
                        'excavator'             => 'Excavator',
                        'bulldozer'             => 'Bulldozer',
                        'wheel_loader'          => 'Wheel Loader',
                        'kendaraan_operasional' => 'Kendaraan Operasional',
                    ])
                    ->default(null)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->typeFilter = $state ?: null),

                Select::make('business_unit_id')
                    ->label('Lini Bisnis')
                    ->options(function () use ($tenant) {
                        $out = [null => 'Semua lini'];
                        if (! $tenant) return $out;
                        BusinessUnit::withoutGlobalScopes()
                            ->where('company_id', $tenant->getKey())
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->each(function ($bu) use (&$out) {
                                $out[$bu->id] = "[{$bu->code}] {$bu->name}";
                            });
                        return $out;
                    })
                    ->default(null)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->businessUnitId = $state ? (int) $state : null),

                Select::make('only_losing')
                    ->label('Tampilkan')
                    ->options([
                        '0' => 'Semua aset',
                        '1' => '⚠ Hanya yang rugi per unit',
                    ])
                    ->default('0')
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->onlyLosing = (string) $state === '1'),
            ]);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        $report = app(AssetCostPerUnitService::class)->getReport(
            $tenant->getKey(),
            $this->year,
            $this->month,
            [
                'type'             => $this->typeFilter,
                'business_unit_id' => $this->businessUnitId,
                'only_losing'      => $this->onlyLosing,
            ],
        );

        return array_merge($report, [
            'companyName'  => $tenant->name,
            'typeFilter'   => $this->typeFilter,
            'onlyLosing'   => $this->onlyLosing,
        ]);
    }
}
