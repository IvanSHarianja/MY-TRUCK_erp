<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

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

        $data['company_id']  = $tenant->getKey();
        $data['created_by']  = auth()->id();
        $data['status']      = 'draft';
        $data['paid_amount'] = 0;

        // Nomor invoice di-generate saat issue (bukan saat draft dibuat)
        // Untuk draft, isi placeholder dulu
        $data['invoice_number'] = 'DRAFT-' . now()->format('ymdHis');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
