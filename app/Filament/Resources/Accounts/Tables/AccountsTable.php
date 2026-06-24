<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Models\Account;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                    ->sortable()
                    ->formatStateUsing(function ($state, $record) {
                        // Indentasi child account dengan prefix arrow
                        if ($record->parent_code) {
                            return '└─ ' . $state;
                        }
                        return $state;
                    }),

                TextColumn::make('name')
                    ->label('Nama Akun')
                    ->searchable()
                    ->wrap()
                    ->weight(fn ($record) => $record->isHeader() ? 'bold' : null),

                TextColumn::make('hierarchy_status')
                    ->label('Tipe')
                    ->getStateUsing(function ($record) {
                        if ($record->isHeader()) return 'HEADER';
                        if ($record->parent_code) return 'CHILD';
                        return 'LEAF';
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'HEADER' => 'warning',
                        'CHILD'  => 'info',
                        'LEAF'   => 'success',
                    })
                    ->tooltip(fn ($state) => match ($state) {
                        'HEADER' => 'Akun induk — tidak bisa di-post langsung di jurnal',
                        'CHILD'  => 'Sub-akun (bisa di-post)',
                        'LEAF'   => 'Akun mandiri (bisa di-post)',
                        default  => null,
                    }),

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

                SelectFilter::make('hierarchy')
                    ->label('Tipe Hierarki')
                    ->options([
                        'header' => 'HEADER (punya sub-akun)',
                        'leaf'   => 'LEAF / CHILD (bisa di-post)',
                    ])
                    ->query(function ($query, array $data) {
                        if (! ($data['value'] ?? null)) return $query;
                        if ($data['value'] === 'header') return $query->headers();
                        return $query->postable();
                    }),

                TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->recordActions([
                // Action "Tambah Sub-Akun" — buat child dari record ini
                Action::make('add_child')
                    ->label('+ Sub-Akun')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('success')
                    ->url(function (Account $record) {
                        $tenant = Filament::getTenant();
                        return \App\Filament\Resources\Accounts\AccountResource::getUrl('create', [
                            'parent_code' => $record->code,
                            'tenant'      => $tenant,
                        ]);
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
