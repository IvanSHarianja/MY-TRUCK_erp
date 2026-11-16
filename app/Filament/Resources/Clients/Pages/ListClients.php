<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Concerns\ImportsPartiesFromExcel;
use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClients extends ListRecords
{
    use ImportsPartiesFromExcel;

    protected static string $resource = ClientResource::class;

    protected function partyKind(): string
    {
        return 'client';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->importExcelAction(),
            CreateAction::make(),
        ];
    }
}
