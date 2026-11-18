<?php

namespace App\Filament\Resources\MaterialSales\Pages;

use App\Enums\Permission;
use App\Filament\Resources\MaterialSales\MaterialSaleResource;
use App\Models\Client;
use App\Models\Material;
use App\Services\Accounting\MaterialMarginService;
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

/** Laporan margin per penjualan material: pendapatan − HPP − biaya kirim. */
class MaterialMarginReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = MaterialSaleResource::class;

    protected string $view = 'filament.resources.material-sales.margin-report';

    protected static ?string $title = 'Margin Penjualan Material';

    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->canCurrent(Permission::ReportsView) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'dari'   => now()->startOfMonth()->toDateString(),
            'sampai' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(4)
            ->components([
                DatePicker::make('dari')->label('Dari')->native(false)->displayFormat('d/m/Y')->required()->live(),
                DatePicker::make('sampai')->label('Sampai')->native(false)->displayFormat('d/m/Y')->required()->live(),
                Select::make('client_id')->label('Klien')->placeholder('Semua klien')->searchable()->live()
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id')),
                Select::make('material_id')->label('Material')->placeholder('Semua material')->searchable()->live()
                    ->options(fn () => Material::query()->jual()->orderBy('name')->pluck('name', 'id')),
            ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: ?int, 3: ?int} */
    public function filters(): array
    {
        $dari   = Carbon::parse($this->data['dari'] ?? now()->startOfMonth())->startOfDay();
        $sampai = Carbon::parse($this->data['sampai'] ?? now())->startOfDay();
        if ($dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return [$dari, $sampai, ($this->data['client_id'] ?? null) ? (int) $this->data['client_id'] : null,
            ($this->data['material_id'] ?? null) ? (int) $this->data['material_id'] : null];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->canCurrent(Permission::ReportsExportPdf) ?? false)
                ->url(function (): string {
                    [$dari, $sampai, $client, $material] = $this->filters();

                    return route('export.margin-material', array_filter([
                        'tenant' => Filament::getTenant()->slug, 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(),
                        'client_id' => $client, 'material_id' => $material,
                    ]));
                }),
        ];
    }

    protected function getViewData(): array
    {
        [$dari, $sampai, $client, $material] = $this->filters();

        return [
            'dari'   => $dari,
            'sampai' => $sampai,
            'report' => app(MaterialMarginService::class)->report(Filament::getTenant()->getKey(), $dari, $sampai, $client, $material),
        ];
    }
}
