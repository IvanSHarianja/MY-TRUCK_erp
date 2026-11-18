<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\RequiresReportPermission;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Services\Accounting\GeneralLedgerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Buku Besar: mutasi & saldo berjalan per akun untuk periode DARI–SAMPAI.
 * Saldo akhir tiap akun = saldo akun itu di Neraca Saldo per tanggal SAMPAI.
 */
class GeneralLedger extends Page implements HasForms
{
    use InteractsWithForms;
    use RequiresReportPermission;

    protected string $view = 'filament.pages.reports.general-ledger';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    // Urutan siklus akuntansi: Jurnal → Buku Besar → Neraca Saldo → laporan.
    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Buku Besar';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'account_id' => null,
            'dari'       => now()->startOfMonth()->toDateString(),
            'sampai'     => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Select::make('account_id')
                    ->label('Akun')
                    ->placeholder('Semua akun')
                    ->options(fn () => app(GeneralLedgerService::class)->accountOptions(Filament::getTenant()->getKey()))
                    ->searchable()
                    ->live(),

                DatePicker::make('dari')
                    ->label('Dari tanggal')
                    ->native(false)->displayFormat('d/m/Y')
                    ->required()
                    ->live(),

                DatePicker::make('sampai')
                    ->label('Sampai tanggal')
                    ->native(false)->displayFormat('d/m/Y')
                    ->required()
                    ->live(),
            ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: ?int} */
    public function filters(): array
    {
        $dari   = Carbon::parse($this->data['dari'] ?? now()->startOfMonth());
        $sampai = Carbon::parse($this->data['sampai'] ?? now());
        if ($dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $accountId = ($this->data['account_id'] ?? null) ? (int) $this->data['account_id'] : null;

        return [$dari->startOfDay(), $sampai->startOfDay(), $accountId];
    }

    /** @return array<string, mixed> parameter URL untuk PDF / Excel */
    private function exportParams(): array
    {
        [$dari, $sampai, $accountId] = $this->filters();

        return array_filter([
            'tenant'     => Filament::getTenant()->slug,
            'dari'       => $dari->toDateString(),
            'sampai'     => $sampai->toDateString(),
            'account_id' => $accountId,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('export.buku-besar', $this->exportParams()))
                ->visible(fn (): bool => static::canExportPdf()),

            Action::make('export_pdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('pdf.general-ledger', $this->exportParams()))
                ->visible(fn (): bool => static::canExportPdf())
                ->openUrlInNewTab(),
        ];
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        [$dari, $sampai, $accountId] = $this->filters();

        $ledger = app(GeneralLedgerService::class)
            ->build($tenant->getKey(), $dari, $sampai, $accountId, GeneralLedgerService::SCREEN_LINE_LIMIT);

        return [
            'companyName' => $tenant->name,
            'dari'        => $dari,
            'sampai'      => $sampai,
            'ledger'      => $ledger,
            // Viewer boleh melihat laporan tapi belum tentu boleh membuka jurnal.
            'journalUrl'  => JournalEntryResource::canViewAny()
                ? fn (int $id): string => JournalEntryResource::getUrl('edit', ['record' => $id])
                : null,
        ];
    }
}
