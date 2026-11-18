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
                // Log per hari bisa 0 rit — cek keberadaan log, bukan jumlah rit.
                ->visible(fn (): bool => ! $this->record->ritLogs()->exists() && ! $this->record->mobilizations()->exists()),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
