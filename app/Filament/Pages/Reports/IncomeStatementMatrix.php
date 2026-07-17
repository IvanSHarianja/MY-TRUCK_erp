<?php

namespace App\Filament\Pages\Reports;

use App\Services\Accounting\IncomeStatementMatrixService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IncomeStatementMatrix extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.income-statement-matrix';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Laba Rugi per Lini';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Laporan Laba Rugi — Segmentasi per Lini Bisnis';

    /**
     * Hidden dari navigation — sudah dikonsolidasi ke IncomeStatementUnified
     * (tab "Per Lini Bisnis"). URL lama tetap accessible untuk bookmark.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();
        return [
            Action::make('export_pdf')
                ->label('Export PDF (Landscape)')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => route('pdf.income-statement-matrix', [
                    'tenant' => $tenant->slug,
                    'year'   => $this->year,
                    'month'  => $this->month,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public ?array $data = [];

    public int $year;

    public ?int $month = null;

    public function mount(): void
    {
        $tenant      = Filament::getTenant();
        $this->year  = $tenant?->fiscal_year ?? now()->year;
        $this->month = (int) now()->month;

        $this->form->fill([
            'year'  => $this->year,
            'month' => $this->month,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
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
            ]);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $report = app(IncomeStatementMatrixService::class)->getReport(
            $tenant->getKey(),
            $this->year,
            $this->month,
        );

        $periodLabel = $this->month
            ? \Carbon\Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y')
            : "Tahun {$this->year}";

        return array_merge($report, [
            'periodLabel' => $periodLabel,
            'companyName' => $tenant->name,
        ]);
    }
}
