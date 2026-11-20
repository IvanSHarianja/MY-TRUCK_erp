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
                // Tanggal beli hasil import yang ternyata sudah benar tidak pernah
                // "diubah", jadi tanda ⚠ tidak hilang sendiri — konfirmasi di sini.
                \Filament\Actions\Action::make('mark_complete')
                    ->label('Data Benar')
                    ->icon(\Filament\Support\Icons\Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (Asset $record): bool => str_contains((string) $record->notes, AssetImportService::INCOMPLETE_MARKER)
                        && (float) $record->purchase_price > 0)
                    ->authorize(fn (Asset $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Asset $record): string => 'Tanggal beli ' . $record->purchase_date?->translatedFormat('d F Y')
                        . ' dan harga beli Rp ' . number_format((float) $record->purchase_price, 0, ',', '.') . ' sudah benar?')
                    ->action(fn (Asset $record) => $record->update([
                        'notes' => AssetImportService::resolveIncompleteNote($record->notes, priceFilled: true, dateChanged: true),
                    ])),
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
