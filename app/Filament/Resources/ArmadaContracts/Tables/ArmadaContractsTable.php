<?php

namespace App\Filament\Resources\ArmadaContracts\Tables;

use App\Enums\Permission;
use App\Models\ArmadaContract;
use App\Services\Accounting\ArmadaContractService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ArmadaContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withUnbilled())
            ->defaultSort('contract_number', 'desc')
            ->columns([
                TextColumn::make('contract_number')
                    ->label('No. Kontrak')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('client.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->limit(25),

                TextColumn::make('route_description')
                    ->label('Rute / Uraian')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->route_description),

                TextColumn::make('tarif_per_rit')
                    ->label('Tarif')
                    ->money('IDR', 0)
                    ->description(fn (ArmadaContract $record): string => 'per ' . $record->unitLabel()),

                TextColumn::make('total_rit')
                    ->label('Total Rit')
                    ->getStateUsing(fn ($record) => $record->total_rit)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('billed_rit')
                    ->label('Rit Ditagih')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                // "120 M³ + 1 mobilisasi alat" — mengikuti satuan kontrak.
                TextColumn::make('unbilled_label')
                    ->label('Belum Ditagih')
                    ->getStateUsing(fn (ArmadaContract $record): string => $record->unbilledLabel() ?: 'Lunas tagih')
                    ->badge()
                    ->color(fn (ArmadaContract $record) => $record->hasUnbilled() ? 'warning' : 'success'),

                TextColumn::make('nilai_siap_tagih')
                    ->label('Nilai Siap Tagih')
                    ->money('IDR')
                    ->getStateUsing(fn ($record) => $record->nilai_siap_tagih)
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'aktif'   => 'success',
                        'selesai' => 'gray',
                        'batal'   => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'aktif'   => 'Aktif',
                        'selesai' => 'Selesai',
                        'batal'   => 'Batal',
                    ]),
            ])
            ->recordActions([
                // Tagih → bikin Invoice otomatis
                Action::make('tagih')
                    ->label('Tagih')
                    ->icon(Heroicon::Banknotes)
                    ->color('warning')
                    // authorize(): sembunyikan DAN tolak eksekusi di server (Viewer dulu bisa menagih).
                    ->authorize(fn (): bool => auth()->user()?->canCurrent(Permission::InvoiceCreate) ?? false)
                    ->visible(fn (ArmadaContract $r): bool => $r->isAktif() && $r->hasUnbilled())
                    ->schema([
                        DatePicker::make('invoice_date')
                            ->label('Tanggal Invoice')
                            ->required()
                            ->default(now())
                            ->native(false),
                    ])
                    ->modalHeading(fn (ArmadaContract $r) => "Tagih {$r->unbilledLabel()} — " . 'Rp ' . number_format($r->nilai_siap_tagih, 0, ',', '.'))
                    ->modalDescription('Invoice akan otomatis dibuat & diterbitkan (jurnal piutang langsung di-post).')
                    ->action(function (array $data, ArmadaContract $record) {
                        try {
                            $invoice = app(ArmadaContractService::class)->tagih(
                                $record,
                                \Carbon\Carbon::parse($data['invoice_date']),
                            );
                            Notification::make()
                                ->title('Invoice ' . $invoice->invoice_number . ' terbit')
                                ->body('Nilai: Rp ' . number_format($invoice->amount, 0, ',', '.') . ' — jurnal di-post otomatis.')
                                ->success()
                                ->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal tagih')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('selesai')
                    ->label('Tutup Kontrak')
                    ->icon(Heroicon::CheckCircle)
                    ->color('gray')
                    ->authorize(fn (): bool => auth()->user()?->canCurrent(Permission::OperationalManage) ?? false)
                    ->visible(fn (ArmadaContract $r): bool => $r->isAktif() && ! $r->hasUnbilled())
                    ->requiresConfirmation()
                    ->modalHeading('Tutup kontrak?')
                    ->modalDescription('Setelah ditutup, tidak bisa input log harian atau mobilisasi alat baru di kontrak ini.')
                    ->action(function (ArmadaContract $record) {
                        try {
                            app(ArmadaContractService::class)->selesai($record);
                            Notification::make()->title('Kontrak ditutup')->success()->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal tutup')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make(),
            ]);
    }
}
