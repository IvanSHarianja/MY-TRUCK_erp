<?php

namespace App\Filament\Pages\Reports;

use App\Services\Accounting\BalanceSheetService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BalanceSheet extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.balance-sheet';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Neraca';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Neraca (Balance Sheet)';

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
                    ->label('Per Tanggal Akhir Bulan')
                    ->options([
                        null => 'Akhir Tahun (kumulatif)',
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
        $report = app(BalanceSheetService::class)->getReport(
            $tenant->getKey(),
            $this->year,
            $this->month,
        );

        $periodLabel = $this->month
            ? 'Per ' . \Carbon\Carbon::create($this->year, $this->month, 1)->endOfMonth()->translatedFormat('d F Y')
            : "Per 31 Desember {$this->year}";

        return array_merge($report, [
            'periodLabel' => $periodLabel,
            'companyName' => $tenant->name,
        ]);
    }
}
