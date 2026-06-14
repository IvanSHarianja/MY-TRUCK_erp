<?php

namespace App\Filament\Resources\MaterialSales\Pages;

use App\Filament\Resources\MaterialSales\MaterialSaleResource;
use App\Services\Accounting\MaterialSaleService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterialSale extends CreateRecord
{
    protected static string $resource = MaterialSaleResource::class;

    protected static bool $canCreateAnother = false;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCreateFormAction()->formId('form')->label('Simpan & Post Jurnal'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * Override default create flow: pakai MaterialSaleService.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $sale = app(MaterialSaleService::class)->create($data);

            Notification::make()
                ->title('Penjualan tersimpan & jurnal otomatis di-post')
                ->body($sale->isInvoice()
                    ? 'Invoice ' . $sale->invoice->invoice_number . ' otomatis terbit (piutang).'
                    : 'Jurnal Kas/Pendapatan otomatis di-post.')
                ->success()
                ->send();

            return $sale;
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()
                ->title('Gagal simpan penjualan')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
