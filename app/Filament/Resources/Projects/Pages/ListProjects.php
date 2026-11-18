<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('margin')
                ->label('Laporan Margin Proyek')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->visible(fn (): bool => ProjectMarginReport::canAccess())
                ->url(fn (): string => ProjectResource::getUrl('margin')),
            CreateAction::make()->label('Daftarkan Proyek Baru'),
        ];
    }
}
