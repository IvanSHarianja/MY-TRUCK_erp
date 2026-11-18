<?php

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * Draft yang menunggu diposting Owner: Saldo Awal, atau ada kas/bank yang
     * berkurang DAN kas/bank yang bertambah (= JournalEntry::needsOwnerApproval,
     * dihitung dari withCount supaya tidak query per baris).
     */
    public static function isPendingTransfer(JournalEntry $r): bool
    {
        return $r->isDraft() && ($r->document_type === 'saldo_awal'
            || ((int) ($r->cash_in_count ?? 0) > 0 && (int) ($r->cash_out_count ?? 0) > 0));
    }

    public static function pendingLabel(JournalEntry $r): string
    {
        return $r->document_type === 'saldo_awal'
            ? 'Saldo awal — menunggu persetujuan Owner'
            : 'Pindah dana — menunggu persetujuan Owner';
    }

    public static function canApproveTransfer(): bool
    {
        return auth()->user()?->canCurrent(\App\Enums\Permission::CashTransferApprove) ?? false;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $cash = array_map('intval', Account::cashAccountIds(Filament::getTenant()?->getKey() ?? 0)) ?: [0];

                return $query->withCount([
                    'lines as cash_in_count'  => fn ($l) => $l->whereIn('account_id', $cash)->where('debit', '>', 0),
                    'lines as cash_out_count' => fn ($l) => $l->whereIn('account_id', $cash)->where('kredit', '>', 0),
                ]);
            })
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
                    ->color(fn (string $state, JournalEntry $record): string => match (true) {
                        static::isPendingTransfer($record) => 'warning',
                        $state === 'posted' => 'success',
                        $state === 'void'   => 'danger',
                        default             => 'gray',
                    })
                    ->description(fn (JournalEntry $record): ?string => static::isPendingTransfer($record)
                        ? static::pendingLabel($record) : null),

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
                Filter::make('menunggu_owner')
                    ->label('Menunggu persetujuan Owner (pindah dana / saldo awal)')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->pendingOwnerApproval(Filament::getTenant()?->getKey() ?? 0)),
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
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->authorize(fn (): bool => auth()->user()?->canCurrent(\App\Enums\Permission::JournalPost) ?? false)
                    // Draft pindah dana: tombol Post hanya untuk pemberi persetujuan (Owner).
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft()
                        && (! static::isPendingTransfer($record) || static::canApproveTransfer()))
                    ->label(fn (JournalEntry $record): string => static::isPendingTransfer($record) ? 'Setujui & Post' : 'Post')
                    ->requiresConfirmation()
                    ->modalHeading(fn (JournalEntry $record): string => static::isPendingTransfer($record)
                        ? ($record->document_type === 'saldo_awal' ? 'Setujui saldo awal?' : 'Setujui pindah dana?') : 'Post Jurnal')
                    ->modalDescription(fn (JournalEntry $record): string => static::isPendingTransfer($record)
                        ? 'Rp ' . number_format((float) $record->total_amount, 0, ',', '.') . ' — ' . $record->description
                            . '. Setelah di-post, saldo rekening berubah dan jurnal hanya bisa di-void.'
                        : 'Setelah di-post, jurnal tidak bisa diedit lagi. Hanya bisa di-void (dibalik).')
                    // Sidik isi saat jendela dibuka — tolak bila draft diubah sebelum dikonfirmasi.
                    ->fillForm(fn (JournalEntry $record): array => ['fingerprint' => $record->contentFingerprint()])
                    ->schema([\Filament\Forms\Components\Hidden::make('fingerprint')])
                    ->action(function (JournalEntry $record, array $data) {
                        $fresh = $record->fresh();
                        if (! $fresh || ! $fresh->isDraft() || ($data['fingerprint'] ?? null) !== $fresh->contentFingerprint()) {
                            Notification::make()->warning()->title('Jurnal berubah')
                                ->body('Draft ini diubah atau sudah diproses orang lain sejak jendela konfirmasi dibuka. Periksa lagi sebelum memposting.')
                                ->persistent()->send();

                            return;
                        }
                        try {
                            app(JournalService::class)->post($fresh);
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
                    ->visible(fn (JournalEntry $record): bool => $record->isPosted()
                        && (auth()->user()?->can('void', $record) ?? false))
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
