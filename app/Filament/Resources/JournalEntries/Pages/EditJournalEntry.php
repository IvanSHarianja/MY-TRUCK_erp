<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditJournalEntry extends EditRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label('Post Jurnal')
                ->icon(Heroicon::CheckCircle)
                ->color('success')
                ->visible(fn (): bool => $this->record->isDraft())
                ->requiresConfirmation()
                ->modalHeading('Post Jurnal')
                ->modalDescription('Setelah di-post, jurnal tidak bisa diedit lagi.')
                ->action(function () {
                    try {
                        // Simpan dulu perubahan yang belum disimpan
                        $this->save(shouldRedirect: false);

                        app(JournalService::class)->post($this->record->refresh());

                        Notification::make()
                            ->title('Jurnal berhasil di-post')
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()
                            ->title('Gagal post jurnal')
                            ->body(collect($e->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make()
                ->visible(fn (): bool => $this->record->isDraft()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Update total_amount dari sum debit
        $data['total_amount'] = collect($data['lines'] ?? [])->sum('debit');

        if (! empty($data['entry_date'])) {
            $date = \Carbon\Carbon::parse($data['entry_date']);
            $data['period_year']  = $date->year;
            $data['period_month'] = $date->month;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
