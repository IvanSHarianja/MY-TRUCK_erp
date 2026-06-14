<?php

namespace App\Filament\Resources\ArmadaContracts\Pages;

use App\Filament\Resources\ArmadaContracts\ArmadaContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListArmadaContracts extends ListRecords
{
    protected static string $resource = ArmadaContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat Kontrak Baru'),
        ];
    }
}
