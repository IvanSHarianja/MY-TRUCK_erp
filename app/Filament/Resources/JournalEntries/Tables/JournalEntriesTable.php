<?php

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Support\Icons\Heroicon;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('entry_date', 'desc')
            ->columns([
                TextColumn::make('entry_number')
                    ->label('No. Jurnal')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('entry_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('document_number')
                    ->label('No. Bukti')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('document_type')
                    ->label('Jenis')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('businessUnit.code')
                    ->label('Lini')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->description),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('IDR', 0)
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft'  => 'gray',
                        'posted' => 'success',
                        'void'   => 'danger',
                        default  => 'gray',
                    }),

                TextColumn::make('createdBy.name')
                    ->label('Dibuat oleh')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('posted_at')
                    ->label('Tgl Post')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft'  => 'Draft',
                        'posted' => 'Posted',
                        'void'   => 'Void',
                    ]),
                SelectFilter::make('document_type')
                    ->label('Jenis Bukti')
                    ->options([
                        'manual'      => 'Manual',
                        'invoice'     => 'Invoice',
                        'bkm'         => 'BKM',
                        'bkk'         => 'BKK',
                        'jual_beli'   => 'Jual Beli',
                        'penyusutan'  => 'Penyusutan',
                        'penyesuaian' => 'Penyesuaian',
                        'penutup'     => 'Penutup',
                        'pembalik'    => 'Pembalik',
                        'saldo_awal'  => 'Saldo Awal',
                    ]),
                SelectFilter::make('business_unit_id')
                    ->label('Lini Bisnis')
                    ->relationship('businessUnit', 'name'),
                SelectFilter::make('period_month')
                    ->label('Bulan')
                    ->options([
                        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                    ]),
            ])
            ->recordActions([
                Action::make('post')
                    ->label('Post')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft())
                    ->requiresConfirmation()
                    ->modalHeading('Post Jurnal')
                    ->modalDescription('Setelah di-post, jurnal tidak bisa diedit lagi. Hanya bisa di-void (dibalik).')
                    ->action(function (JournalEntry $record) {
                        try {
                            app(JournalService::class)->post($record);
                            Notification::make()
                                ->title('Jurnal berhasil di-post')
                                ->success()
                                ->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal post jurnal')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('void')
                    ->label('Void')
                    ->icon(Heroicon::XCircle)
                    ->color('danger')
                    ->visible(fn (JournalEntry $record): bool => $record->isPosted())
                    ->requiresConfirmation()
                    ->modalHeading('Void Jurnal')
                    ->modalDescription('Akan dibuatkan jurnal pembalik otomatis. Jurnal asli tetap ada (untuk audit trail).')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan Void')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (JournalEntry $record, array $data) {
                        try {
                            app(JournalService::class)->void($record, $data['reason']);
                            Notification::make()
                                ->title('Jurnal di-void & jurnal pembalik dibuat')
                                ->success()
                                ->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal void jurnal')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make()
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function ($records) {
                            $deleted = 0;
                            foreach ($records as $record) {
                                if ($record->isDraft()) {
                                    $record->delete();
                                    $deleted++;
                                }
                            }
                            Notification::make()
                                ->title("$deleted jurnal draft dihapus")
                                ->body('Jurnal posted/void tidak ikut dihapus (audit trail).')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
