<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Concerns\ImportsPartiesFromExcel;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    use ImportsPartiesFromExcel;

    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importExcelAction(),
            CreateAction::make(),
        ];
    }
}
