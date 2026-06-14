<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\Accounting\InvoiceService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Save → hanya untuk draft
            $this->getSaveFormAction()
                ->formId('form')
                ->visible(fn (): bool => $this->record->isDraft()),

            $this->getCancelFormAction(),

            Action::make('issue')
                ->label('Terbitkan Invoice')
                ->icon(Heroicon::CheckCircle)
                ->color('success')
                ->visible(fn (): bool => $this->record->isDraft())
                ->requiresConfirmation()
                ->modalHeading('Terbitkan Invoice')
                ->modalDescription('Invoice akan diterbitkan dan jurnal otomatis di-post.')
                ->action(function () {
                    try {
                        $this->save(shouldRedirect: false);
                        app(InvoiceService::class)->issue($this->record->refresh());
                        Notification::make()->title('Invoice diterbitkan & jurnal di-post')->success()->send();
                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()
                            ->title('Gagal terbitkan invoice')
                            ->body(collect($e->errors())->flatten()->implode(' '))
                            ->danger()->send();
                    }
                }),

            DeleteAction::make()
                ->visible(fn (): bool => $this->record->isDraft()),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
