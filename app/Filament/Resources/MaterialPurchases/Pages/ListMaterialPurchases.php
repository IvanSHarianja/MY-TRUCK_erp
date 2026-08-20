<?php

namespace App\Filament\Resources\MaterialPurchases\Pages;

use App\Filament\Resources\MaterialPurchases\MaterialPurchaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaterialPurchases extends ListRecords
{
    protected static string $resource = MaterialPurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Input Pembelian'),
        ];
    }
}
