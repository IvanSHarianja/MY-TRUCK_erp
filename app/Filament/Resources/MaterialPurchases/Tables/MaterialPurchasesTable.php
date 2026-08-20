<?php

namespace App\Filament\Resources\MaterialPurchases\Tables;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaterialPurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('purchase_number')
                    ->label('No. Pembelian')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('purchase_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('material.name')
                    ->label('Material')
                    ->searchable()
                    ->description(fn ($record) => $record->material?->code
                        ? '[' . $record->material->code . '] ' . $record->material->satuan
                        : null),

                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('unit_price')
                    ->label('Harga/Unit')
                    ->money('IDR', 0)
                    ->alignEnd(),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('IDR', 0)
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('payment_method')
                    ->label('Bayar')
                    ->badge()
                    ->color(fn ($state) => $state === 'tunai' ? 'success' : 'warning'),

                TextColumn::make('journalEntry.entry_number')
                    ->label('Jurnal')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('purchase_date', 'desc')
            ->recordActions([
                Action::make('view_journal')
                    ->label('Lihat Jurnal')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->visible(fn ($record) => $record->journal_entry_id !== null)
                    ->url(fn ($record) => \App\Filament\Resources\JournalEntries\JournalEntryResource::getUrl(
                        'edit',
                        ['record' => $record->journal_entry_id, 'tenant' => \Filament\Facades\Filament::getTenant()?->slug],
                    )),
            ]);
    }
}
