<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\RequiresReportPermission;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Services\Accounting\DailyCashReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Laporan Harian Keuangan: posisi kas per rekening, mutasi kas, invoice
 * terbit, pembayaran diterima, cicilan dibayar, dan yang jatuh tempo — untuk
 * satu tanggal. Lihat DailyCashReportService.
 */
class DailyReport extends Page implements HasForms
{
    use InteractsWithForms;
    use RequiresReportPermission;

    protected string $view = 'filament.pages.reports.daily-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Laporan Harian';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = -1;

    protected static ?string $title = 'Laporan Harian Keuangan';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['tanggal' => now()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                DatePicker::make('tanggal')
                    ->label('Tanggal')
                    ->native(false)->displayFormat('d/m/Y')
                    ->required()
                    ->maxDate(now())
                    ->live(),
            ]);
    }

    public function tanggal(): Carbon
    {
        return Carbon::parse($this->data['tanggal'] ?? now())->startOfDay();
    }

    public function shiftDay(int $days): void
    {
        $next = $this->tanggal()->addDays($days);
        if ($next->lte(today())) {
            $this->data['tanggal'] = $next->toDateString();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Hari sebelumnya')->icon(Heroicon::OutlinedChevronLeft)->color('gray')
                ->action(fn () => $this->shiftDay(-1)),
            Action::make('next')->label('Hari berikutnya')->icon(Heroicon::OutlinedChevronRight)->iconPosition('after')->color('gray')
                ->disabled(fn (): bool => $this->tanggal()->gte(today()))
                ->action(fn () => $this->shiftDay(1)),
            Action::make('export_pdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('pdf.daily-report', [
                    'tenant' => Filament::getTenant()->slug, 'tanggal' => $this->tanggal()->toDateString(),
                ]))
                ->visible(fn (): bool => static::canExportPdf())
                ->openUrlInNewTab(),
        ];
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        return [
            'companyName' => $tenant->name,
            'report'      => app(DailyCashReportService::class)->build($tenant->getKey(), $this->tanggal()),
            'journalUrl'  => JournalEntryResource::canViewAny()
                ? fn (int $id): string => JournalEntryResource::getUrl('edit', ['record' => $id])
                : null,
        ];
    }
}
