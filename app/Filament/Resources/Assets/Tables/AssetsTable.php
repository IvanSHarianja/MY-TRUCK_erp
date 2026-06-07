<?php

namespace App\Filament\Resources\Assets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('asset_code', 'asc')
            ->columns([
                TextColumn::make('asset_code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('plate_number')
                    ->label('Nopol')
                    ->toggleable(),
                TextColumn::make('purchase_date')
                    ->label('Tgl Beli')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('purchase_price')
                    ->label('Harga Beli')
                    ->money('IDR', 0)
                    ->sortable(),
                TextColumn::make('useful_life_months')
                    ->label('Umur')
                    ->suffix(' bln')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'aktif'       => 'success',
                        'maintenance' => 'warning',
                        'non_aktif'   => 'gray',
                        default       => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options([
                        'dump_truck'            => 'Dump Truck',
                        'excavator'             => 'Excavator',
                        'bulldozer'             => 'Bulldozer',
                        'wheel_loader'          => 'Wheel Loader',
                        'kendaraan_operasional' => 'Kendaraan Operasional',
                        'peralatan_kantor'      => 'Peralatan Kantor',
                        'lainnya'               => 'Lainnya',
                    ]),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'aktif'       => 'Aktif',
                        'maintenance' => 'Maintenance',
                        'non_aktif'   => 'Non-Aktif',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
