<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ImportPartiesPage;

class ImportClients extends ImportPartiesPage
{
    protected static string $resource = ClientResource::class;

    protected static function kind(): string
    {
        return 'client';
    }
}
