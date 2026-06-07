<?php

namespace App\Filament\Resources\Accounts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AccountsTable
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
                    ->label('Nama Akun')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'aset'       => 'info',
                        'kewajiban'  => 'warning',
                        'ekuitas'    => 'success',
                        'pendapatan' => 'success',
                        'beban'      => 'danger',
                        'penutup'    => 'gray',
                        default      => 'gray',
                    }),

                TextColumn::make('sub_category')
                    ->label('Sub-Kategori')
                    ->toggleable(),

                TextColumn::make('normal_balance')
                    ->label('Saldo Normal')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'debit' ? 'info' : 'warning'),

                TextColumn::make('cash_flow_category')
                    ->label('Arus Kas')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('tax_type')
                    ->label('Pajak')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options([
                        'aset'       => 'Aset',
                        'kewajiban'  => 'Kewajiban',
                        'ekuitas'    => 'Ekuitas',
                        'pendapatan' => 'Pendapatan',
                        'beban'      => 'Beban',
                        'penutup'    => 'Akun Penutup',
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
