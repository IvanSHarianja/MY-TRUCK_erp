<?php

namespace App\Filament\Pages\Reports;

use App\Services\Accounting\TrialBalanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TrialBalance extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.trial-balance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Neraca Saldo (Trial Balance)';

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();
        return [
            Action::make('export_pdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => route('pdf.trial-balance', [
                    'tenant' => $tenant->slug,
                    'year'   => $this->year,
                    'month'  => $this->month,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public ?array $data = [];

    public int $year;

    public ?int $month;

    public function mount(): void
    {
        $tenant     = Filament::getTenant();
        $this->year = $tenant?->fiscal_year ?? now()->year;
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
                        1    => 'Januari',
                        2    => 'Februari',
                        3    => 'Maret',
                        4    => 'April',
                        5    => 'Mei',
                        6    => 'Juni',
                        7    => 'Juli',
                        8    => 'Agustus',
                        9    => 'September',
                        10   => 'Oktober',
                        11   => 'November',
                        12   => 'Desember',
                    ])
                    ->default((int) now()->month)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->month = $state ? (int) $state : null),
            ]);
    }

    protected function getViewData(): array
    {
        $tenant  = Filament::getTenant();
        $service = app(TrialBalanceService::class);

        $byCategory = $service->getBalancesByCategory($tenant->getKey(), $this->year, $this->month);
        $grandTotal = $service->getGrandTotal($tenant->getKey(), $this->year, $this->month);

        $monthLabel = $this->month
            ? \Carbon\Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y')
            : "Tahun {$this->year} (kumulatif)";

        return [
            'byCategory'  => $byCategory,
            'grandTotal'  => $grandTotal,
            'periodLabel' => $monthLabel,
            'companyName' => $tenant->name,
        ];
    }
}
