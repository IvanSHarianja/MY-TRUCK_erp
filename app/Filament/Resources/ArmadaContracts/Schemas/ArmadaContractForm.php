<?php

namespace App\Filament\Resources\ArmadaContracts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ArmadaContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Kontrak')
                    ->columns(2)
                    ->schema([
                        TextInput::make('contract_number')
                            ->label('No. Kontrak')
                            ->placeholder('Otomatis')
                            ->disabled()
                            ->dehydrated(false),

                        Select::make('client_id')
                            ->label('Pelanggan')
                            ->relationship('client', 'name', fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->required(),

                        DatePicker::make('started_at')
                            ->label('Tanggal Mulai')
                            ->default(now())
                            ->native(false),

                        Select::make('status')
                            ->label('Status')
                            ->default('aktif')
                            ->required()
                            ->options([
                                'aktif'   => 'Aktif',
                                'selesai' => 'Selesai',
                                'batal'   => 'Batal',
                            ])
                            ->native(false),
                    ]),

                Section::make('Detail Angkutan')
                    ->columns(2)
                    ->schema([
                        Textarea::make('route_description')
                            ->label('Uraian / Rute Angkutan')
                            ->required()
                            ->rows(2)
                            ->placeholder('contoh: Angkutan tanah urug Kuari → Kawasan Industri (12 km)')
                            ->columnSpanFull(),

                        TextInput::make('tarif_per_rit')
                            ->label('Tarif per Rit (Rp)')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(1)
                            ->placeholder('contoh: 250000'),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
