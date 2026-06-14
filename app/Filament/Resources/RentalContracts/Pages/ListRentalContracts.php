<?php

namespace App\Filament\Resources\RentalContracts\Pages;

use App\Filament\Resources\RentalContracts\RentalContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRentalContracts extends ListRecords
{
    protected static string $resource = RentalContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat Kontrak Rental'),
        ];
    }
}
