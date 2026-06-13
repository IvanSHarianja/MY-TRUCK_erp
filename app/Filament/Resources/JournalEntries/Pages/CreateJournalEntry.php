<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Services\Accounting\JournalService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    /**
     * Hilangkan tombol "Create & create another".
     */
    protected static bool $canCreateAnother = false;

    /**
     * Pindahkan tombol Create + Cancel ke pojok kanan atas (header).
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->formId('form'),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Kosongkan tombol di bawah form supaya tidak duplikat.
     */
    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $date   = \Carbon\Carbon::parse($data['entry_date'] ?? now());

        $data['company_id']    = $tenant->getKey();
        $data['created_by']    = auth()->id();
        $data['status']        = 'draft';
        $data['period_year']   = $date->year;
        $data['period_month']  = $date->month;
        $data['entry_number']  = app(JournalService::class)->generateEntryNumber($tenant, $date);

        // total_amount dihitung dari debit sum
        $data['total_amount'] = collect($data['lines'] ?? [])->sum('debit');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
