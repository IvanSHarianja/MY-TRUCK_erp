<?php

namespace App\Filament\Pages\Reports;

use App\Services\Accounting\IncomeStatementByAssetService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IncomeStatementByAsset extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.income-statement-by-asset';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Laba Rugi per Unit';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Laporan Laba Rugi per Unit (Aset)';

    /**
     * Hidden dari navigation — sudah dikonsolidasi ke IncomeStatementUnified
     * (tab "Per Unit"). URL lama tetap accessible untuk bookmark.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public ?array $data = [];

    public int $year;
    public ?int $month = null;
    public ?string $typeFilter = null;
    public bool $onlyWithActivity = true;

    public function mount(): void
    {
        $tenant      = Filament::getTenant();
        $this->year  = $tenant?->fiscal_year ?? now()->year;
        $this->month = (int) now()->month;

        $this->form->fill([
            'year'  => $this->year,
            'month' => $this->month,
            'type_filter'        => null,
            'only_with_activity' => true,
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
                ->url(fn () => route('pdf.income-statement-by-asset', [
                    'tenant' => $tenant->slug,
                    'year'   => $this->year,
                    'month'  => $this->month,
                    'type'   => $this->typeFilter,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
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
                        null => 'Semua bulan (kumulatif tahun)',
                        1    => 'Januari',  2 => 'Februari', 3 => 'Maret',     4 => 'April',
                        5    => 'Mei',      6 => 'Juni',     7 => 'Juli',      8 => 'Agustus',
                        9    => 'September',10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                    ])
                    ->default((int) now()->month)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->month = $state ? (int) $state : null),

                Select::make('type_filter')
                    ->label('Filter Jenis Aset')
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

                Select::make('only_with_activity')
                    ->label('Tampilkan')
                    ->options([
                        '1' => 'Hanya aset yang punya aktivitas jurnal',
                        '0' => 'Semua aset (termasuk yang belum ada transaksi)',
                    ])
                    ->default('1')
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->onlyWithActivity = (string) $state === '1'),
            ]);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $report = app(IncomeStatementByAssetService::class)->getReport(
            $tenant->getKey(),
            $this->year,
            $this->month,
        );

        // Filter by type kalau ada
        if ($this->typeFilter) {
            $report['assets'] = array_values(array_filter(
                $report['assets'],
                fn ($row) => $row['type'] === $this->typeFilter,
            ));
        }

        // Filter hanya aset dengan aktivitas kalau di-toggle
        if ($this->onlyWithActivity) {
            $report['assets'] = array_values(array_filter(
                $report['assets'],
                fn ($row) => $row['has_activity'],
            ));
        }

        $periodLabel = $this->month
            ? \Carbon\Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y')
            : "Tahun {$this->year}";

        return array_merge($report, [
            'periodLabel' => $periodLabel,
            'companyName' => $tenant->name,
            'typeFilter'  => $this->typeFilter,
        ]);
    }
}
