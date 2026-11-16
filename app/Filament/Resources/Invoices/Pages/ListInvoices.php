<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('report')
                ->label('Laporan Piutang')
                ->icon(\Filament\Support\Icons\Heroicon::OutlinedChartBar)
                ->color('gray')
                ->url(fn (): string => InvoiceResource::getUrl('report'))
                ->visible(fn (): bool => ReceivableReport::canAccess()),

            CreateAction::make()
                ->label('Buat Invoice Baru'),
        ];
    }
}
