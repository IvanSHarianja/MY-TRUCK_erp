<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Filament\Resources\Debts\DebtResource;
use App\Services\Accounting\DebtService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateDebt extends CreateRecord
{
    protected static string $resource = DebtResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(DebtService::class)->create(Filament::getTenant(), auth()->user(), $data);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Gagal mencatat hutang')
                ->body(collect($e->errors())->flatten()->implode(' '))->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Hutang & jadwal cicilan tersimpan';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
