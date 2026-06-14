<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProgressUpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'progressUpdates';

    protected static ?string $title = 'Riwayat Update Progress';

    public function form(Schema $schema): Schema
    {
        return $schema;  // Read-only — update progress dilakukan via action di kontrak
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('update_date', 'desc')
            ->columns([
                TextColumn::make('update_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('progress_pct')
                    ->label('Progress')
                    ->getStateUsing(fn ($record) => number_format($record->progress_pct, 1) . '%')
                    ->badge()
                    ->color('info'),

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->wrap(),

                TextColumn::make('createdBy.name')
                    ->label('Update Oleh')
                    ->toggleable(),
            ]);
    }
}
