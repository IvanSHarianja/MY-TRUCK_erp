<?php

namespace App\Filament\Resources\MaterialPurchases\Pages;

use App\Filament\Resources\MaterialPurchases\MaterialPurchaseResource;
use App\Services\Accounting\MaterialPurchaseService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterialPurchase extends CreateRecord
{
    protected static string $resource = MaterialPurchaseResource::class;

    /**
     * Bypass default create — pakai service supaya auto-journal + stock + MAC.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $purchase = app(MaterialPurchaseService::class)->record([
                'material_id'     => $data['material_id'],
                'vendor_id'       => $data['vendor_id'] ?? null,
                'purchase_date'   => $data['purchase_date'],
                'qty'             => $data['qty'],
                'unit_price'      => $data['unit_price'],
                'payment_method'  => $data['payment_method'],
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'bukti_tf_path'   => $data['bukti_tf_path'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            Notification::make()
                ->title('Pembelian tersimpan + jurnal + stok update')
                ->body('Nomor: ' . $purchase->purchase_number)
                ->success()
                ->send();

            return $purchase;
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()
                ->title('Gagal simpan pembelian')
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
