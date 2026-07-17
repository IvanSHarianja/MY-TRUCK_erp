<?php

namespace App\Filament\Pages\Reports;

use App\Models\BusinessUnit;
use App\Services\Accounting\IncomeStatementByAssetService;
use App\Services\Accounting\IncomeStatementMatrixService;
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

/**
 * Halaman terkonsolidasi Laba Rugi — 3 dimensi tampilan dalam 1 page:
 *
 *   - "ringkasan" → IncomeStatementService (total company, filter by BU opsional)
 *   - "per_lini"  → IncomeStatementMatrixService (kolom per BU)
 *   - "per_unit"  → IncomeStatementByAssetService (row per aset)
 *
 * Filter periode (tahun+bulan) diterapkan ke semua tab supaya user bisa
 * bandingkan dimensi tanpa reset filter. State tab ter-persist di URL
 * via Livewire query string.
 */
class IncomeStatementUnified extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.reports.income-statement-unified';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laba Rugi';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Laporan Laba Rugi';

    public ?array $data = [];

    public int $year;
    public ?int $month = null;
    public ?int $businessUnitId = null;
    public ?string $assetTypeFilter = null;

    /**
     * Tab aktif — di-persist di URL biar user bisa bookmark tampilan tertentu.
     * @var string
     */
    public string $activeTab = 'ringkasan';

    protected function queryString(): array
    {
        return [
            'activeTab' => ['except' => 'ringkasan'],
        ];
    }

    public function mount(): void
    {
        $tenant      = Filament::getTenant();
        $this->year  = $tenant?->fiscal_year ?? now()->year;
        $this->month = (int) now()->month;

        $this->form->fill([
            'year'              => $this->year,
            'month'             => $this->month,
            'business_unit_id'  => null,
            'asset_type_filter' => null,
        ]);
    }

    public function setActiveTab(string $tab): void
    {
        if (in_array($tab, ['ringkasan', 'per_lini', 'per_unit'], true)) {
            $this->activeTab = $tab;
        }
    }

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();

        return [
            Action::make('export_pdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(function () use ($tenant) {
                    // PDF route berbeda per dimensi — arahkan sesuai tab aktif.
                    return match ($this->activeTab) {
                        'per_lini' => route('pdf.income-statement-matrix', [
                            'tenant' => $tenant->slug,
                            'year'   => $this->year,
                            'month'  => $this->month,
                        ]),
                        'per_unit' => route('pdf.income-statement-by-asset', [
                            'tenant' => $tenant->slug,
                            'year'   => $this->year,
                            'month'  => $this->month,
                            'type'   => $this->assetTypeFilter,
                        ]),
                        default => route('pdf.income-statement', [
                            'tenant'           => $tenant->slug,
                            'year'             => $this->year,
                            'month'            => $this->month,
                            'business_unit_id' => $this->businessUnitId,
                        ]),
                    };
                })
                ->openUrlInNewTab(),
        ];
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
            ->columns(4)
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

                // Filter khusus tab Ringkasan: pilih lini bisnis.
                Select::make('business_unit_id')
                    ->label('Lini Bisnis (Ringkasan)')
                    ->options([null => 'Semua Lini'] + $businessUnits)
                    ->default(null)
                    ->visible(fn (): bool => $this->activeTab === 'ringkasan')
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->businessUnitId = $state ? (int) $state : null),

                // Filter khusus tab Per Unit: pilih jenis aset.
                Select::make('asset_type_filter')
                    ->label('Jenis Aset (Per Unit)')
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
                    ->visible(fn (): bool => $this->activeTab === 'per_unit')
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->assetTypeFilter = $state ?: null),
            ]);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $tenantId = $tenant->getKey();

        $periodLabel = $this->month
            ? \Carbon\Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y')
            : "Tahun {$this->year}";

        $base = [
            'activeTab'   => $this->activeTab,
            'periodLabel' => $periodLabel,
            'companyName' => $tenant->name,
        ];

        // Load data sesuai tab aktif (lazy — tab lain tidak query DB).
        if ($this->activeTab === 'ringkasan') {
            $report = app(IncomeStatementService::class)->getReport(
                $tenantId, $this->year, $this->month, $this->businessUnitId,
            );
            $unitLabel = $this->businessUnitId
                ? BusinessUnit::find($this->businessUnitId)?->name
                : 'Semua Lini Bisnis';
            return array_merge($base, $report, ['unitLabel' => $unitLabel]);
        }

        if ($this->activeTab === 'per_lini') {
            $report = app(IncomeStatementMatrixService::class)->getReport(
                $tenantId, $this->year, $this->month,
            );
            return array_merge($base, $report);
        }

        // per_unit
        $report = app(IncomeStatementByAssetService::class)->getReport(
            $tenantId, $this->year, $this->month,
        );

        // Filter by asset type
        if ($this->assetTypeFilter) {
            $report['assets'] = array_values(array_filter(
                $report['assets'],
                fn ($row) => $row['type'] === $this->assetTypeFilter,
            ));
        }

        // Filter default: hanya aset dengan aktivitas jurnal
        $report['assets'] = array_values(array_filter(
            $report['assets'],
            fn ($row) => $row['has_activity'],
        ));

        return array_merge($base, $report, ['typeFilter' => $this->assetTypeFilter]);
    }
}
