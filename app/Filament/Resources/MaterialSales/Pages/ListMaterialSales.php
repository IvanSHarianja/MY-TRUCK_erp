<?php

namespace App\Filament\Resources\MaterialSales\Pages;

use App\Filament\Resources\MaterialSales\MaterialSaleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMaterialSales extends ListRecords
{
    protected static string $resource = MaterialSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('margin')
                ->label('Laporan Margin')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->visible(fn (): bool => MaterialMarginReport::canAccess())
                ->url(fn (): string => MaterialSaleResource::getUrl('margin')),
            CreateAction::make()->label('Buat Penjualan Baru'),
        ];
    }
}
