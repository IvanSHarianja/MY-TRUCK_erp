<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Support\ImportPartiesPage;

class ImportVendors extends ImportPartiesPage
{
    protected static string $resource = VendorResource::class;

    protected static function kind(): string
    {
        return 'vendor';
    }
}
