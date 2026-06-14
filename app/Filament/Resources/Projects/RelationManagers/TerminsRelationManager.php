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
                    ->color('success'),

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
            ]);
    }
}
