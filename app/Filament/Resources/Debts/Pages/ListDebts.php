<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Debts\Widgets\UpcomingInstallments;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDebts extends ListRecords
{
    protected static string $resource = DebtResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Catat Hutang')];
    }

    protected function getHeaderWidgets(): array
    {
        return [UpcomingInstallments::class];
    }
}
