<?php

namespace App\Filament\Resources\MaterialSales\Pages;

use App\Filament\Resources\MaterialSales\MaterialSaleResource;
use Filament\Resources\Pages\EditRecord;

class EditMaterialSale extends EditRecord
{
    protected static string $resource = MaterialSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCancelFormAction(),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * View-only — penjualan yang sudah ada jurnal tidak boleh diedit langsung.
     * Untuk koreksi, void invoice/jurnal terkait dan buat penjualan baru.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->form->disabled();
    }
}
