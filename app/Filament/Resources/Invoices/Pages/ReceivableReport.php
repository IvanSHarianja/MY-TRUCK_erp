<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use App\Services\Accounting\ReceivableReportService;
use App\Support\PaymentTiming;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Laporan Tagihan & Piutang: umur piutang per klien (per tanggal SAMPAI),
 * invoice belum lunas, dan riwayat pembayaran dalam periode DARI–SAMPAI.
 */
class ReceivableReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = InvoiceResource::class;

    protected string $view = 'filament.resources.invoices.receivable-report';

    protected static ?string $title = 'Laporan Tagihan & Piutang';

    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->canCurrent(Permission::ReportsView) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'dari'      => now()->startOfYear()->toDateString(),
            'sampai'    => now()->toDateString(),
            'client_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                DatePicker::make('dari')
                    ->label('Pembayaran dari')
                    ->native(false)->displayFormat('d/m/Y')
                    ->live()
                    ->required(),

                DatePicker::make('sampai')
                    ->label('Sampai (posisi piutang per tanggal ini)')
                    ->native(false)->displayFormat('d/m/Y')
                    ->live()
                    ->required(),

                Select::make('client_id')
                    ->label('Klien')
                    ->placeholder('Semua klien')
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('export.piutang', [
                    'tenant'    => Filament::getTenant()->slug,
                    'dari'      => $this->period()[0]->toDateString(),
                    'sampai'    => $this->period()[1]->toDateString(),
                    'client_id' => $this->data['client_id'] ?? null,
                ])),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(): array
    {
        $dari   = Carbon::parse($this->data['dari'] ?? now()->startOfYear());
        $sampai = Carbon::parse($this->data['sampai'] ?? now());

        return $dari->gt($sampai) ? [$sampai, $sampai] : [$dari, $sampai];
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        [$dari, $sampai] = $this->period();
        $clientId = ($this->data['client_id'] ?? null) ? (int) $this->data['client_id'] : null;
        $service  = app(ReceivableReportService::class);

        $aging = $service->agingByClient($tenant->getKey(), $sampai, $clientId);

        $totals = ['total_sisa' => (float) $aging->sum('total_sisa')];
        foreach (array_keys(PaymentTiming::BUCKETS) as $b) {
            $totals["bucket_{$b}"] = (float) $aging->sum("bucket_{$b}");
        }

        return [
            'companyName' => $tenant->name,
            'dari'        => $dari,
            'sampai'      => $sampai,
            'buckets'     => PaymentTiming::BUCKETS,
            'aging'       => $aging->filter(fn ($r) => $r['total_sisa'] > 0 || $r['lunas_jumlah'] > 0),
            'totals'      => $totals,
            'outstanding' => $service->outstandingInvoices($tenant->getKey(), $sampai, $clientId),
            'payments'    => $service->payments($tenant->getKey(), $dari, $sampai, $clientId),
        ];
    }
}
