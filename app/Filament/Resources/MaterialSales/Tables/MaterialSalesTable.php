<?php

namespace App\Filament\Resources\MaterialSales\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MaterialSalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sale_date', 'desc')
            ->columns([
                TextColumn::make('sale_number')
                    ->label('No. Penjualan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('sale_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('material.name')
                    ->label('Material')
                    ->searchable(),

                TextColumn::make('volume')
                    ->label('Volume')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn ($record) => ' ' . optional($record->material)->satuan),

                TextColumn::make('harga_satuan')
                    ->label('Harga')
                    ->money('IDR')
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('IDR')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('metode')
                    ->label('Metode')
                    ->badge()
                    ->color(fn (string $state) => $state === 'tunai' ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state) => $state === 'tunai' ? '💰 Tunai' : '🧾 Invoice'),

                TextColumn::make('invoice.invoice_number')
                    ->label('No. Invoice')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('metode')
                    ->options([
                        'tunai'   => 'Tunai',
                        'invoice' => 'Invoice',
                    ]),

                SelectFilter::make('material_id')
                    ->label('Material')
                    ->relationship('material', 'name'),
            ]);
    }
}
