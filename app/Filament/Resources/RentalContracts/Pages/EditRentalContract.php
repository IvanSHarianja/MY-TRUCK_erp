<?php

namespace App\Filament\Resources\RentalContracts\Pages;

use App\Filament\Concerns\RequiresContractApproval;
use App\Filament\Resources\RentalContracts\RentalContractResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRentalContract extends EditRecord
{
    use RequiresContractApproval;

    protected static string $resource = RentalContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Simpan = ajukan perubahan (approval Owner) — lihat RequiresContractApproval.
            $this->submitChangeAction(),

            $this->getCancelFormAction(),

            DeleteAction::make()
                // BUG-19: epsilon tolerance (bukan strict === 0.0)
                ->visible(fn (): bool => abs((float) $this->record->billed_jam) < 0.005 && abs((float) $this->record->total_jam) < 0.005),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
