<?php

namespace App\Filament\Resources\RentalContracts\Tables;

use App\Models\RentalContract;
use App\Services\Accounting\RentalContractService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RentalContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('contract_number', 'desc')
            ->columns([
                TextColumn::make('contract_number')
                    ->label('No. Kontrak')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('asset.asset_code')
                    ->label('Unit')
                    ->badge(),

                TextColumn::make('client.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->limit(25),

                TextColumn::make('lokasi_kerja')
                    ->label('Lokasi')
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('tarif_per_jam')
                    ->label('Tarif/Jam')
                    ->money('IDR'),

                TextColumn::make('total_jam')
                    ->label('Total Jam')
                    ->getStateUsing(fn ($record) => $record->total_jam . ' jam')
                    ->alignEnd(),

                TextColumn::make('billed_jam')
                    ->label('Tertagih')
                    ->getStateUsing(fn ($record) => number_format($record->billed_jam, 2, ',', '.') . ' jam')
                    ->alignEnd(),

                TextColumn::make('unbilled_jam')
                    ->label('Belum Ditagih')
                    ->getStateUsing(fn ($record) => number_format($record->unbilled_jam, 2, ',', '.') . ' jam')
                    ->badge()
                    ->color(fn ($record) => $record->unbilled_jam > 0 ? 'warning' : 'success'),

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
                Action::make('tagih')
                    ->label('Tagih')
                    ->icon(Heroicon::Banknotes)
                    ->color('warning')
                    ->visible(fn (RentalContract $r): bool => $r->isAktif() && $r->unbilled_jam > 0)
                    ->schema([
                        DatePicker::make('invoice_date')
                            ->label('Tanggal Invoice')
                            ->required()
                            ->default(now())
                            ->native(false),
                    ])
                    ->modalHeading(fn (RentalContract $r) => 'Tagih ' . $r->unbilled_jam . ' jam — Rp ' . number_format($r->nilai_siap_tagih, 0, ',', '.'))
                    ->modalDescription('Invoice akan otomatis dibuat & diterbitkan (jurnal piutang otomatis di-post).')
                    ->action(function (array $data, RentalContract $record) {
                        try {
                            $invoice = app(RentalContractService::class)->tagih(
                                $record,
                                \Carbon\Carbon::parse($data['invoice_date']),
                            );
                            Notification::make()
                                ->title('Invoice ' . $invoice->invoice_number . ' terbit')
                                ->body('Nilai: Rp ' . number_format($invoice->amount, 0, ',', '.'))
                                ->success()->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal tagih')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()->send();
                        }
                    }),

                Action::make('selesai')
                    ->label('Tutup Kontrak')
                    ->icon(Heroicon::CheckCircle)
                    ->color('gray')
                    ->visible(fn (RentalContract $r): bool => $r->isAktif() && $r->unbilled_jam <= 0)
                    ->requiresConfirmation()
                    ->action(function (RentalContract $record) {
                        try {
                            app(RentalContractService::class)->selesai($record);
                            Notification::make()->title('Kontrak ditutup')->success()->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal tutup')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()->send();
                        }
                    }),

                EditAction::make(),
            ]);
    }
}
