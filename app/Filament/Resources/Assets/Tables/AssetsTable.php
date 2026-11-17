<?php

namespace App\Filament\Resources\Assets\Tables;

use App\Models\Asset;
use App\Services\Import\AssetImportService;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Filters\Filter;
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
                    ->searchable()
                    // Aset hasil import yang datanya belum lengkap (harga / tanggal beli).
                    ->description(fn (Asset $record): ?string => self::incompleteNote($record))
                    ->color(fn (Asset $record): ?string => self::incompleteNote($record) ? 'warning' : null),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->formatStateUsing(fn (?string $state) => AssetImportService::TYPES[$state] ?? $state)
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
                Filter::make('perlu_dilengkapi')
                    ->label('Perlu dilengkapi (hasil import)')
                    ->toggle()
                    // Nama parameter WAJIB $query — Filament menyuntik berdasarkan nama.
                    ->query(fn ($query) => $query->where('notes', 'like', '%' . AssetImportService::INCOMPLETE_MARKER . '%')),
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(AssetImportService::TYPES),
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

    /** "Perlu dilengkapi: harga beli." dari catatan import, atau null. */
    private static function incompleteNote(Asset $record): ?string
    {
        $notes = (string) $record->notes;
        $pos = mb_strpos($notes, AssetImportService::INCOMPLETE_MARKER);
        if ($pos === false) {
            return null;
        }
        $rest = mb_substr($notes, $pos);
        $end  = mb_strpos($rest, '.');

        return '⚠ ' . ($end === false ? $rest : mb_substr($rest, 0, $end));
    }
}
