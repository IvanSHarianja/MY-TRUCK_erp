<?php

namespace App\Filament\Resources\MaterialSales\Pages;

use App\Filament\Resources\MaterialSales\MaterialSaleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaterialSales extends ListRecords
{
    protected static string $resource = MaterialSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat Penjualan Baru'),
        ];
    }
}
