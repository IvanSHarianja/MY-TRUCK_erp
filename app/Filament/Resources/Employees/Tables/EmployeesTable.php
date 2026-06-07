<?php

namespace App\Filament\Resources\Employees\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('employee_id', 'asc')
            ->columns([
                TextColumn::make('employee_id')
                    ->label('NIK')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('position')
                    ->label('Posisi')
                    ->badge(),
                TextColumn::make('assignedAsset.name')
                    ->label('Aset')
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('Telepon')
                    ->toggleable(),
                TextColumn::make('join_date')
                    ->label('Tgl Masuk')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('position')
                    ->label('Posisi')
                    ->options([
                        'driver'   => 'Driver',
                        'operator' => 'Operator',
                        'mandor'   => 'Mandor',
                        'admin'    => 'Admin',
                        'mekanik'  => 'Mekanik',
                        'lainnya'  => 'Lainnya',
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
