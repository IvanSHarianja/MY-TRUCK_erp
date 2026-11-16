<?php

namespace App\Filament\Resources\ArmadaContracts\Pages;

use App\Filament\Concerns\RequiresContractApproval;
use App\Filament\Resources\ArmadaContracts\ArmadaContractResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditArmadaContract extends EditRecord
{
    use RequiresContractApproval;

    protected static string $resource = ArmadaContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Simpan = ajukan perubahan (approval Owner) — lihat RequiresContractApproval.
            $this->submitChangeAction(),

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
