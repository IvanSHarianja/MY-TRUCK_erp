<?php

namespace App\Filament\Resources\MaterialSales\Tables;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MaterialSalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sale_date', 'desc')
            ->columns([
                TextColumn::make('sale_number')
                    ->label('No. Penjualan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('sale_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('material.name')
                    ->label('Material')
                    ->searchable(),

                TextColumn::make('volume')
                    ->label('Jumlah')
                    ->state(fn ($record) => $record->quantityLabel()),

                TextColumn::make('harga_satuan')
                    ->label('Harga')
                    ->money('IDR')
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('IDR')
                    ->sortable()
                    ->weight('bold'),

                // Margin = total − HPP − biaya kirim (detail di Laporan Margin).
                TextColumn::make('margin')
                    ->label('Margin')
                    ->state(fn ($record) => $record->margin())
                    ->money('IDR', 0)
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                    ->description(fn ($record) => (float) $record->biaya_kirim_total > 0
                        ? 'kirim Rp ' . number_format((float) $record->biaya_kirim_total, 0, ',', '.')
                        : null)
                    ->toggleable(),

                TextColumn::make('metode')
                    ->label('Metode')
                    ->badge()
                    ->color(fn (string $state) => $state === 'tunai' ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state) => $state === 'tunai' ? '💰 Tunai' : '🧾 Invoice')
                    ->description(fn (\App\Models\MaterialSale $record): ?string => $record->isVoided()
                        ? 'DIBATALKAN — ' . $record->void_reason : null),

                TextColumn::make('invoice.invoice_number')
                    ->label('No. Invoice')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('metode')
                    ->options([
                        'tunai'   => 'Tunai',
                        'invoice' => 'Invoice',
                    ]),

                SelectFilter::make('material_id')
                    ->label('Material')
                    ->relationship('material', 'name'),
            ])
            ->recordActions([
                Action::make('lihat_bukti')->liatBukti(),

                // Penjualan tunai: batal = void jurnal + stok kembali + HPP & biaya kirim dibalik.
                Action::make('batalkan')
                    ->label('Batalkan')
                    ->icon(\Filament\Support\Icons\Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize(fn (): bool => auth()->user()?->canCurrent(\App\Enums\Permission::JournalVoid) ?? false)
                    ->visible(fn (\App\Models\MaterialSale $record): bool => $record->isTunai() && ! $record->isVoided())
                    ->requiresConfirmation()
                    ->modalHeading(fn (\App\Models\MaterialSale $record) => "Batalkan penjualan {$record->sale_number}?")
                    ->modalDescription('Jurnal penjualan, HPP, dan biaya kirim dibalik; stok material dikembalikan. Penjualan tetap tercatat dengan tanda DIBATALKAN.')
                    ->schema([\Filament\Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2)])
                    ->action(function (\App\Models\MaterialSale $record, array $data) {
                        try {
                            app(\App\Services\Accounting\MaterialSaleService::class)->voidTunai($record, auth()->user(), $data['reason']);
                            \Filament\Notifications\Notification::make()->success()->title('Penjualan dibatalkan')->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            \Filament\Notifications\Notification::make()->danger()->title('Gagal membatalkan')
                                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                        }
                    }),
            ]);
    }
}
