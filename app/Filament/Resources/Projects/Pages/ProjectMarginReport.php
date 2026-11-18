<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\Accounting\ProjectMarginService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;

/** Margin per proyek borongan: pendapatan termin − biaya pemakaian unit sendiri. */
class ProjectMarginReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.margin-report';

    protected static ?string $title = 'Margin Proyek Borongan';

    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->canCurrent(Permission::ReportsView) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['status' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Select::make('status')->label('Status Proyek')->placeholder('Semua proyek')->live()
                    ->options(['berjalan' => 'Berjalan', 'selesai' => 'Selesai', 'batal' => 'Batal']),
            ]);
    }

    protected function getViewData(): array
    {
        return [
            'report' => app(ProjectMarginService::class)->report(Filament::getTenant()->getKey(), $this->data['status'] ?? null),
        ];
    }
}
