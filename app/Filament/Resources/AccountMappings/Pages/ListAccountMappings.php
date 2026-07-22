<?php

namespace App\Filament\Resources\AccountMappings\Pages;

use App\Filament\Resources\AccountMappings\AccountMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountMappings extends ListRecords
{
    protected static string $resource = AccountMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
