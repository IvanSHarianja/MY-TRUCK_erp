<?php

namespace App\Filament\Resources\ArmadaContracts\Pages;

use App\Filament\Resources\ArmadaContracts\ArmadaContractResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditArmadaContract extends EditRecord
{
    protected static string $resource = ArmadaContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->formId('form')
                ->visible(fn (): bool => $this->record->isAktif()),

            $this->getCancelFormAction(),

            DeleteAction::make()
                ->visible(fn (): bool => $this->record->billed_rit === 0 && $this->record->total_rit === 0),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
