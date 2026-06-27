<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Models\Account;
use App\Models\JournalEntryLine;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

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

                // Action: Migrasi transaksi legacy parent → child default
                Action::make('migrate_to_child')
                    ->label('Migrasi ke Sub-Akun')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('warning')
                    ->visible(function (Account $record) {
                        // Hanya muncul untuk HEADER yang punya legacy journal lines
                        if (! $record->isHeader()) return false;
                        return JournalEntryLine::where('account_id', $record->id)->exists();
                    })
                    ->schema([
                        Select::make('target_child_id')
                            ->label('Pindahkan ke Sub-Akun')
                            ->required()
                            ->options(function (Account $record) {
                                return $record->children()
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText('Semua jurnal line yang post ke akun parent akan dipindah ke sub-akun ini.'),
                    ])
                    ->modalHeading(fn (Account $record) =>
                        'Migrasi Transaksi Legacy: ' . $record->code)
                    ->modalDescription(function (Account $record) {
                        $count = JournalEntryLine::where('account_id', $record->id)->count();
                        return "Ada {$count} jurnal line yang post langsung ke akun parent [{$record->code}] {$record->name}. "
                            . "Aksi ini akan memindahkan semua transaksi tersebut ke sub-akun yang Anda pilih. "
                            . "Saldo akhir tetap sama, hanya berubah secara representasi.";
                    })
                    ->requiresConfirmation()
                    ->action(function (array $data, Account $record) {
                        $targetChild = Account::withoutGlobalScopes()->find($data['target_child_id']);

                        if (! $targetChild || $targetChild->parent_code !== $record->code) {
                            Notification::make()
                                ->title('Sub-akun target tidak valid')
                                ->danger()->send();
                            return;
                        }

                        DB::transaction(function () use ($record, $targetChild) {
                            $count = JournalEntryLine::where('account_id', $record->id)
                                ->update(['account_id' => $targetChild->id]);

                            Notification::make()
                                ->title("✅ {$count} jurnal line dipindahkan")
                                ->body("Dari [{$record->code}] → [{$targetChild->code}] {$targetChild->name}")
                                ->success()->send();
                        });
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
