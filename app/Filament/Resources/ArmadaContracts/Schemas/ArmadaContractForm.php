<?php

namespace App\Filament\Resources\ArmadaContracts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                            ->relationship('client', 'name', fn($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn($record) => "[{$record->code}] {$record->name}")
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
                                'aktif' => 'Aktif',
                                'selesai' => 'Selesai',
                                'batal' => 'Batal',
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

                Section::make('Tipe Kontrak & Biaya Operasional')
                    ->description('Tentukan siapa yang menanggung BBM & supir. Untuk All In / Semi, isi standar biaya per rit & per hari — nanti auto-terpakai di jurnal setiap log ritase.')
                    ->columns(2)
                    ->schema([
                        Select::make('tipe_kontrak')
                            ->label('Tipe Kontrak')
                            ->native(false)
                            ->required()
                            ->default('alat_saja')
                            ->options([
                                'all_in' => 'All In (BBM + Supir dari PT)',
                                'semi' => 'Semi (Supir dari PT, BBM klien)',
                                'alat_saja' => 'Alat Saja (Klien tanggung semua)',
                            ])
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set) {
                                $set('include_bbm', $state === 'all_in');
                                $set('include_operator', in_array($state, ['all_in', 'semi'], true));
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Standar Biaya BBM (per rit)')
                    ->description('Estimasi konsumsi BBM per rit tergantung jarak rute. Kosongkan harga untuk pakai default company.')
                    ->columns(2)
                    ->visible(fn(Get $get): bool => (bool) $get('include_bbm'))
                    ->schema([
                        TextInput::make('bbm_liter_per_rit')
                            ->label('Konsumsi BBM per Rit')
                            ->numeric()
                            ->step(0.1)
                            ->minValue(0.1)
                            ->suffix('L/rit')
                            ->placeholder('contoh: 15')
                            ->required(fn(Get $get): bool => (bool) $get('include_bbm')),

                        TextInput::make('harga_bbm_per_liter')
                            ->label('Harga BBM (Rp/liter)')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('Kosongkan → pakai default company')
                            ->helperText('Isi bila harga solar berbeda dari default company.'),
                    ]),

                Section::make('Standar Biaya Supir')
                    ->description('Gaji & uang makan flat per hari kerja. Uang jalan & premi per rit.')
                    ->columns(2)
                    ->visible(fn(Get $get): bool => (bool) $get('include_operator'))
                    ->schema([
                        TextInput::make('gaji_supir_per_hari')
                            ->label('Gaji Supir per Hari')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('contoh: 200000')
                            ->required(fn(Get $get): bool => (bool) $get('include_operator')),

                        TextInput::make('uang_makan_per_hari')
                            ->label('Uang Makan per Hari')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('contoh: 50000')
                            ->required(fn(Get $get): bool => (bool) $get('include_operator')),

                        TextInput::make('uang_jalan_per_rit')
                            ->label('Uang Jalan per Rit')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('contoh: 25000')
                            ->helperText('Biaya tol/parkir/dsb per trip. Opsional.'),

                        TextInput::make('premi_per_rit')
                            ->label('Premi per Rit (opsional)')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('contoh: 10000')
                            ->helperText('Insentif per rit. Kosongkan bila tidak ada.'),
                    ]),
            ]);
    }
}
