<?php

namespace App\Filament\Pages\Reports;

use App\Enums\DepreciationMethod;
use App\Models\BusinessUnit;
use App\Services\Accounting\AssetDepreciationReportService;
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
 * BIZ-04 — Laporan Penyusutan per Aset.
 *
 * Tujuan: visibility jadwal + akumulasi + sisa nilai buku per aset,
 * konsisten dengan section Aset Tetap di Neraca.
 */
class AssetDepreciation extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.asset-depreciation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Penyusutan Aset';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Laporan Penyusutan per Aset';

    public ?array $data = [];

    public int $year;
    public int $month;
    public ?string $typeFilter = null;
    public ?int $businessUnitId = null;
    public ?string $methodFilter = null;

    public function mount(): void
    {
        $this->year  = (int) now()->year;
        $this->month = (int) now()->month;

        $this->form->fill([
            'year'             => $this->year,
            'month'            => $this->month,
            'type_filter'      => null,
            'business_unit_id' => null,
            'method_filter'    => null,
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
                ->url(fn () => route('pdf.asset-depreciation', [
                    'tenant'           => $tenant->slug,
                    'year'             => $this->year,
                    'month'            => $this->month,
                    'type'             => $this->typeFilter,
                    'business_unit_id' => $this->businessUnitId,
                    'method'           => $this->methodFilter,
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
                    ->label('Sampai Bulan')
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
                        'peralatan_kantor'      => 'Peralatan Kantor',
                        'lainnya'               => 'Lainnya',
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

                Select::make('method_filter')
                    ->label('Metode')
                    ->options(array_merge(
                        [null => 'Semua metode'],
                        DepreciationMethod::options(),
                    ))
                    ->default(null)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->methodFilter = $state ?: null),
            ]);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        $report = app(AssetDepreciationReportService::class)->getReport(
            $tenant->getKey(),
            $this->year,
            $this->month,
            [
                'type'             => $this->typeFilter,
                'business_unit_id' => $this->businessUnitId,
                'method'           => $this->methodFilter,
            ],
        );

        return array_merge($report, [
            'companyName'  => $tenant->name,
            'typeFilter'   => $this->typeFilter,
            'methodFilter' => $this->methodFilter,
        ]);
    }
}
