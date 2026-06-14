<?php

namespace App\Filament\Resources\ArmadaContracts\Pages;

use App\Filament\Resources\ArmadaContracts\ArmadaContractResource;
use App\Services\Accounting\ArmadaContractService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateArmadaContract extends CreateRecord
{
    protected static string $resource = ArmadaContractResource::class;

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

        $data['company_id'] = $tenant->getKey();
        $data['created_by'] = auth()->id();
        $data['billed_rit'] = 0;

        // Auto-generate contract number
        $data['contract_number'] = app(ArmadaContractService::class)
            ->generateContractNumber($tenant);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
