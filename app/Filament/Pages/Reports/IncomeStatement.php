<?php

namespace App\Filament\Pages\Reports;

use App\Models\BusinessUnit;
use App\Services\Accounting\IncomeStatementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IncomeStatement extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.income-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan Laba Rugi';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Laporan Laba Rugi (Income Statement)';

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();
        return [
            Action::make('export_pdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => route('pdf.income-statement', [
                    'tenant'           => $tenant->slug,
                    'year'             => $this->year,
                    'month'            => $this->month,
                    'business_unit_id' => $this->businessUnitId,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public ?array $data = [];

    public int $year;

    public ?int $month = null;

    public ?int $businessUnitId = null;

    public function mount(): void
    {
        $tenant      = Filament::getTenant();
        $this->year  = $tenant?->fiscal_year ?? now()->year;
        $this->month = (int) now()->month;

        $this->form->fill([
            'year'             => $this->year,
            'month'            => $this->month,
            'business_unit_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $tenant = Filament::getTenant();
        $businessUnits = $tenant
            ? BusinessUnit::where('company_id', $tenant->getKey())
                ->orderBy('code')
                ->pluck('name', 'id')
                ->toArray()
            : [];

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
                        null => 'Semua bulan (kumulatif)',
                        1    => 'Januari',  2 => 'Februari', 3 => 'Maret',     4 => 'April',
                        5    => 'Mei',      6 => 'Juni',     7 => 'Juli',      8 => 'Agustus',
                        9    => 'September',10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                    ])
                    ->default((int) now()->month)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->month = $state ? (int) $state : null),

                Select::make('business_unit_id')
                    ->label('Lini Bisnis')
                    ->options([null => 'Semua Lini'] + $businessUnits)
                    ->default(null)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->businessUnitId = $state ? (int) $state : null),
            ]);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $report = app(IncomeStatementService::class)->getReport(
            $tenant->getKey(),
            $this->year,
            $this->month,
            $this->businessUnitId,
        );

        $periodLabel = $this->month
            ? \Carbon\Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y')
            : "Tahun {$this->year}";

        $unitLabel = $this->businessUnitId
            ? BusinessUnit::find($this->businessUnitId)?->name
            : 'Semua Lini Bisnis';

        return array_merge($report, [
            'periodLabel' => $periodLabel,
            'unitLabel'   => $unitLabel,
            'companyName' => $tenant->name,
        ]);
    }
}
