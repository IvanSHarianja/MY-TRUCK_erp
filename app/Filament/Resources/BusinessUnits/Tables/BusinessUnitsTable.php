<?php

namespace App\Filament\Resources\BusinessUnits\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BusinessUnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code', 'asc')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(60)
                    ->toggleable(),
                ColorColumn::make('color')
                    ->label('Warna'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
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
