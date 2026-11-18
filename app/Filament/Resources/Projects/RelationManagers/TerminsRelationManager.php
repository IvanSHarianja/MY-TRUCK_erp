<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TerminsRelationManager extends RelationManager
{
    protected static string $relationship = 'termins';

    protected static ?string $title = 'Riwayat Tagihan Termin';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('termin_number', 'asc')
            ->columns([
                TextColumn::make('termin_number')
                    ->label('Termin Ke')
                    ->badge()
                    ->color('primary'),

                TextColumn::make('termin_pct')
                    ->label('Persen')
                    ->getStateUsing(fn ($record) => number_format($record->termin_pct, 2) . '%'),

                TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->weight('bold'),

                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->badge()
                    ->color('success')
                    ->placeholder('Lunas dari DP'),

                TextColumn::make('invoice.status')
                    ->label('Status Invoice')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'lunas'    => 'success',
                        'sebagian' => 'warning',
                        'terbit'   => 'info',
                        default    => 'gray',
                    }),

                TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(40)
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label('Tanggal Tagih')
                    ->date('d M Y'),
            ])
            ->recordActions([
                // Termin lunas penuh dari DP tidak punya invoice untuk di-void.
                \Filament\Actions\Action::make('batalkan')
                    ->label('Batalkan')
                    ->icon(\Filament\Support\Icons\Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize(fn (): bool => auth()->user()?->canCurrent(\App\Enums\Permission::JournalVoid) ?? false)
                    ->visible(fn (\App\Models\ProjectTermin $record): bool => ! $record->invoice_id)
                    ->requiresConfirmation()
                    ->modalHeading(fn (\App\Models\ProjectTermin $record) => "Batalkan termin {$record->termin_number}?")
                    ->modalDescription('Jurnal pemotongan DP dibalik, persentase tertagih & sisa DP proyek dikembalikan.')
                    ->schema([\Filament\Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2)])
                    ->action(function (\App\Models\ProjectTermin $record, array $data) {
                        try {
                            app(\App\Services\Accounting\ProjectService::class)->cancelDpOnlyTermin($record, $data['reason']);
                            \Filament\Notifications\Notification::make()->success()->title('Termin dibatalkan')->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            \Filament\Notifications\Notification::make()->danger()->title('Gagal membatalkan')
                                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                        }
                    }),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
