<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Proyek')
                    ->columns(2)
                    ->schema([
                        TextInput::make('project_number')
                            ->label('No. Proyek')
                            ->placeholder('Otomatis')
                            ->disabled()
                            ->dehydrated(false),

                        Select::make('status')
                            ->label('Status')
                            ->default('berjalan')
                            ->required()
                            ->options([
                                'berjalan' => 'Berjalan',
                                'selesai'  => 'Selesai',
                                'batal'    => 'Batal',
                            ])
                            ->native(false),

                        TextInput::make('name')
                            ->label('Nama Proyek')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('contoh: Pengurugan Lahan Pabrik 3 Ha')
                            ->columnSpanFull(),

                        Select::make('client_id')
                            ->label('Pelanggan / Pemberi Kerja')
                            ->relationship('client', 'name', fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('jenis_pekerjaan')
                            ->label('Jenis Pekerjaan')
                            ->required()
                            ->options([
                                'Pengurugan / Pematangan Lahan' => 'Pengurugan / Pematangan Lahan',
                                'Land Clearing'                  => 'Land Clearing',
                                'Cut & Fill'                     => 'Cut & Fill',
                                'Galian & Timbunan'              => 'Galian & Timbunan',
                                'Pengerukan'                     => 'Pengerukan',
                                'Lain-lain'                      => 'Lain-lain',
                            ])
                            ->native(false),

                        TextInput::make('nilai_kontrak')
                            ->label('Nilai Kontrak (Rp)')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(1)
                            ->placeholder('contoh: 1850000000'),

                        DatePicker::make('started_at')
                            ->label('Tanggal Mulai')
                            ->default(now())
                            ->native(false),

                        DatePicker::make('target_end_date')
                            ->label('Target Selesai')
                            ->native(false),
                    ]),

                Section::make('Deskripsi')
                    ->schema([
                        Textarea::make('description')
                            ->label('Deskripsi Pekerjaan')
                            ->rows(3),

                        Textarea::make('notes')
                            ->label('Catatan Internal')
                            ->rows(2),
                    ]),
            ]);
    }
}
