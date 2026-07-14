<?php

namespace App\Filament\Resources\RentalContracts\Pages;

use App\Filament\Resources\RentalContracts\RentalContractResource;
use App\Services\Accounting\RentalContractService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRentalContract extends CreateRecord
{
    protected static string $resource = RentalContractResource::class;

    protected static bool $canCreateAnother = false;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCreateFormAction()->formId('form'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();

        $data['company_id']      = $tenant->getKey();
        $data['created_by']      = auth()->id();
        $data['billed_jam']      = 0;
        $data['contract_number'] = app(RentalContractService::class)->generateContractNumber($tenant);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
