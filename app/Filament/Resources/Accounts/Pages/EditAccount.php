<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Services\CoaImportService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Akun dibuka & disimpan lewat form = Peran sudah diperiksa user →
     * penanda "Peran hasil tebakan import" dibuang (peringatan berkurang).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('notes', $data)) {
            $data['notes'] = CoaImportService::stripGuessedNote($data['notes']);
        }

        return $data;
    }
}
