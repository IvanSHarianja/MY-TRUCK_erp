<?php

namespace App\Filament\Resources\RentalContracts\Schemas;

use App\Models\Asset;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
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
