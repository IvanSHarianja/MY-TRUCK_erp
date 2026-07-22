<?php

namespace App\Filament\Resources\AccountMappings\Pages;

use App\Filament\Resources\AccountMappings\AccountMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountMapping extends EditRecord
{
    protected static string $resource = AccountMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
