<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Models\BusinessUnit;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('asset_code')
                    ->label('Kode Aset')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('DT-01 / EXCA-01')
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        $tenant = Filament::getTenant();
                        return $rule->where('company_id', $tenant?->getKey());
                    })
                    ->validationMessages([
                        'unique' => 'Kode aset ini sudah dipakai. Pilih kode lain.',
                    ]),

                TextInput::make('name')
                    ->label('Nama Aset')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Dump Truck 01 / Excavator PC200'),

                Select::make('type')
                    ->label('Jenis Aset')
                    ->options([
                        'dump_truck'            => 'Dump Truck',
                        'excavator'             => 'Excavator',
                        'bulldozer'             => 'Bulldozer',
                        'wheel_loader'          => 'Wheel Loader',
                        'kendaraan_operasional' => 'Kendaraan Operasional',
                        'peralatan_kantor'      => 'Peralatan Kantor',
                        'lainnya'               => 'Lainnya',
                    ])
                    ->required()
                    ->native(false),

                TextInput::make('plate_number')
                    ->label('Nomor Polisi')
                    ->maxLength(20),

                DatePicker::make('purchase_date')
                    ->label('Tanggal Pembelian')
                    ->native(false),

                TextInput::make('purchase_price')
                    ->label('Harga Beli')
                    ->numeric()
                    ->default(0)
                    ->prefix('Rp')
                    ->required(),

                TextInput::make('useful_life_months')
                    ->label('Umur Ekonomis (bulan)')
                    ->numeric()
                    ->default(60)
                    ->suffix('bulan')
                    ->required(),

                TextInput::make('salvage_value')
                    ->label('Nilai Residu')
                    ->numeric()
                    ->default(0)
                    ->prefix('Rp')
                    ->required(),

                Select::make('account_id')
                    ->label('Akun Aset Tetap')
                    ->relationship(
                        name: 'account',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->where('sub_category', 'aset_tetap')
                            ->postable(),  // ← hanya leaf account
                    )
                    ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}] {$record->name}")
                    ->searchable()
                    ->preload()
                    ->helperText('Akun HEADER otomatis disembunyikan — pilih sub-akun spesifik.'),

                Select::make('default_business_unit_id')
                    ->label('Lini Bisnis Default')
                    ->native(false)
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $q = BusinessUnit::query()->where('is_active', true);
                        if ($tenant) {
                            $q->where('company_id', $tenant->getKey());
                        }
                        return $q->orderBy('code')->get()
                            ->mapWithKeys(fn ($bu) => [$bu->id => "[{$bu->code}] {$bu->name}"])
                            ->toArray();
                    })
                    ->helperText('Dipakai untuk alokasi biaya penyusutan & maintenance. Kosongkan → auto-fallback berdasar jenis aset (dump_truck→ARMD, excavator→RENT, lainnya→UMUM).'),

                Select::make('status')
                    ->label('Status')
                    ->options([
                        'aktif'       => 'Aktif',
                        'maintenance' => 'Maintenance',
                        'non_aktif'   => 'Non-Aktif',
                    ])
                    ->default('aktif')
                    ->required()
                    ->native(false),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
