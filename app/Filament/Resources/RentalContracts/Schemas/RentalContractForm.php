<?php

namespace App\Filament\Resources\RentalContracts\Schemas;

use App\Models\Asset;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class RentalContractForm
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

                        Select::make('asset_id')
                            ->label('Alat Berat')
                            ->required()
                            ->options(function () {
                                $tenant = Filament::getTenant();
                                $query = Asset::query()
                                    ->whereIn('type', ['excavator', 'bulldozer', 'wheel_loader']);
                                if ($tenant) {
                                    $query->where('company_id', $tenant->getKey());
                                }
                                return $query->orderBy('asset_code')->get()
                                    ->mapWithKeys(fn ($a) => [
                                        $a->id => "[{$a->asset_code}] {$a->name} ({$a->status})",
                                    ])
                                    ->toArray();
                            })
                            ->searchable(),

                        TextInput::make('tarif_per_jam')
                            ->label('Tarif per Jam (Rp)')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(1)
                            ->placeholder('contoh: 350000'),

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

                Section::make('Tipe Rental & Biaya Operasional')
                    ->description('Tentukan siapa yang menanggung BBM & operator. Untuk All In / Semi, isi standar biaya harian — nanti auto-terpakai di jurnal setiap log jam kerja.')
                    ->columns(2)
                    ->schema([
                        Select::make('tipe_rental')
                            ->label('Tipe Rental')
                            ->native(false)
                            ->required()
                            ->default('alat_saja')
                            ->options([
                                'all_in'    => 'All In (BBM + Operator dari PT)',
                                'semi'      => 'Semi (Operator dari PT, BBM klien)',
                                'alat_saja' => 'Alat Saja (Dry Rental)',
                            ])
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set) {
                                // Auto-sync flag turunan supaya service tidak perlu parse string.
                                $set('include_bbm', $state === 'all_in');
                                $set('include_operator', in_array($state, ['all_in', 'semi'], true));
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Standar Biaya BBM')
                    ->description('Estimasi konsumsi solar per jam operasi & harga per liter. Kosongkan harga untuk pakai default company.')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => (bool) $get('include_bbm'))
                    ->schema([
                        TextInput::make('bbm_liter_per_jam')
                            ->label('Konsumsi BBM (liter/jam)')
                            ->numeric()
                            ->step(0.1)
                            ->minValue(0.1)
                            ->suffix('L/jam')
                            ->placeholder('contoh: 12.5')
                            ->required(fn (Get $get): bool => (bool) $get('include_bbm')),

                        TextInput::make('harga_bbm_per_liter')
                            ->label('Harga BBM (Rp/liter)')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('Kosongkan → pakai default company')
                            ->helperText('Isi bila harga solar untuk kontrak ini berbeda dari default company.'),
                    ]),

                Section::make('Standar Biaya Operator')
                    ->description('Biaya operator harian & premi. Uang makan & gaji flat per hari kerja.')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => (bool) $get('include_operator'))
                    ->schema([
                        TextInput::make('gaji_operator_per_hari')
                            ->label('Gaji Operator per Hari')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('contoh: 250000')
                            ->required(fn (Get $get): bool => (bool) $get('include_operator')),

                        TextInput::make('uang_makan_per_hari')
                            ->label('Uang Makan per Hari')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('contoh: 50000')
                            ->required(fn (Get $get): bool => (bool) $get('include_operator')),

                        TextInput::make('premi_per_jam')
                            ->label('Premi per Jam Kerja (opsional)')
                            ->numeric()
                            ->prefix('Rp')
                            ->placeholder('contoh: 15000')
                            ->helperText('Insentif per jam operasi. Kosongkan bila tidak ada premi.'),
                    ]),

                Section::make('Detail Pekerjaan')
                    ->schema([
                        Textarea::make('lokasi_kerja')
                            ->label('Lokasi Kerja')
                            ->rows(2)
                            ->placeholder('contoh: Proyek Tol Seksi 2, Desa Sukamulya'),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2),
                    ]),
            ]);
    }
}
