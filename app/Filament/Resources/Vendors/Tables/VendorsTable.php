<?php

namespace App\Filament\Resources\Vendors\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code', 'asc')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('contact_person')
                    ->label('Contact')
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('Telepon')
                    ->toggleable(),
                TextColumn::make('npwp')
                    ->label('NPWP')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options([
                        'kuari'     => 'Kuari',
                        'bbm'       => 'BBM',
                        'sparepart' => 'Sparepart',
                        'jasa'      => 'Jasa',
                        'leasing'   => 'Leasing',
                        'lainnya'   => 'Lainnya',
                    ]),
                TernaryFilter::make('is_active')->label('Status Aktif'),
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
