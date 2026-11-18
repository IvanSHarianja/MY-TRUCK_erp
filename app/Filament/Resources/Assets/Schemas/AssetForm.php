<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\BusinessUnit;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->disabled(fn (?Asset $record): bool => self::locked($record))
                    ->dehydrated(true)
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
                    ->disabled(fn (?Asset $record): bool => self::locked($record))
                    ->dehydrated(true)
                    ->required()
                    ->native(false),

                TextInput::make('purchase_price')
                    ->label('Harga Beli')
                    ->disabled(fn (?Asset $record): bool => self::locked($record))
                    ->dehydrated(true)
                    ->default(0)
                    ->rupiah()
                    ->required(),

                TextInput::make('salvage_value')
                    ->label('Nilai Residu')
                    ->disabled(fn (?Asset $record): bool => self::locked($record))
                    ->dehydrated(true)
                    ->default(0)
                    ->rupiah()
                    ->required(),

                // Aset yang dibeli sebelum memakai MY-TRUCK: penyusutan yang SUDAH
                // terjadi. Tanpa ini aset lama nilai bukunya tetap 100%.
                TextInput::make('akumulasi_awal')
                    ->label('Akumulasi Penyusutan Awal')
                    ->disabled(fn (?Asset $record): bool => self::locked($record))
                    ->dehydrated(true)
                    ->default(0)
                    ->rupiah()
                    ->helperText('Untuk aset yang dibeli sebelum memakai MY-TRUCK: total penyusutan sampai akhir bulan lalu. '
                        . 'Isi 0 untuk aset baru. Saldo di buku besar dicatat lewat jurnal Saldo Awal.')
                    ->suffixAction(
                        \Filament\Actions\Action::make('hitungAkumulasi')
                            ->icon(\Filament\Support\Icons\Heroicon::OutlinedCalculator)
                            ->tooltip('Hitung perkiraan garis lurus dari tanggal beli s/d akhir bulan lalu')
                            ->action(function (Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                                // Parser yang sama dengan import (tahan "100.000.000" & "100000000.00").
                                $money = fn ($v) => app(\App\Services\Import\AssetImportService::class)->money($v) ?? 0;
                                $estimate = (new Asset([
                                    'purchase_date'      => $get('purchase_date'),
                                    'purchase_price'     => $money($get('purchase_price')),
                                    'salvage_value'      => $money($get('salvage_value')),
                                    'useful_life_months' => (int) $get('useful_life_months'),
                                ]))->estimatedAkumulasiAwal();
                                $set('akumulasi_awal', $estimate);
                            })
                            ->disabled(fn (?Asset $record): bool => (bool) $record?->hasPostedDepreciationJournal()),
                    ),

                TextInput::make('monthly_target_hours')
                    ->label('Target Jam per Bulan (Dashboard)')
                    ->numeric()
                    ->step(0.5)
                    ->minValue(0)
                    ->suffix('jam')
                    ->placeholder('Kosongkan → auto')
                    ->helperText('Target utilization aset per bulan. Dipakai untuk widget "Utilization Aset" di Dashboard Operasional. Kalau kosong, sistem otomatis pakai (Umur Ekonomis Jam ÷ 60) atau default 200 jam.'),

                // ========================================================
                // BIZ-02: Metode Penyusutan
                // ========================================================
                Select::make('depreciation_method')
                    ->label('Metode Penyusutan')
                    ->options(DepreciationMethod::options())
                    ->default(DepreciationMethod::StraightLine->value)
                    ->required()
                    ->native(false)
                    ->live()
                    ->disabled(fn (?Asset $record): bool => (bool) $record?->hasPostedDepreciationJournal())
                    ->helperText(function (?Asset $record): string {
                        if ($record?->hasPostedDepreciationJournal()) {
                            return 'Metode dikunci: aset ini sudah punya jurnal penyusutan. '
                                . 'Untuk mengubah, void semua jurnal DEP-* / DEPUSE-* terkait terlebih dahulu.';
                        }
                        return 'Garis Lurus = penyusutan bulanan otomatis. '
                            . 'Per Jam/Rit/Hari = penyusutan otomatis saat log usage di-post (aus per pemakaian).';
                    })
                    // dehydrated tetap true (default) supaya field dikirim ke server
                    // walau disabled — hindari NULL yang bikin update Aset gagal.
                    ->dehydrated(true),

                TextInput::make('useful_life_months')
                    ->label('Umur Ekonomis')
                    ->disabled(fn (?Asset $record): bool => self::locked($record))
                    ->dehydrated(true)
                    ->numeric()
                    ->default(60)
                    ->suffix('bulan')
                    ->required(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::StraightLine->value)
                    ->visible(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::StraightLine->value),

                TextInput::make('useful_life_hours')
                    ->label('Umur Ekonomis')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix('jam')
                    ->placeholder('mis. 10000 (10rb jam operasi)')
                    ->required(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::PerHour->value)
                    ->visible(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::PerHour->value)
                    ->helperText(fn (?Asset $record): ?string => $record?->hasPostedDepreciationJournal()
                        ? '⚠ Aset ini sudah punya jurnal penyusutan. Mengubah umur ekonomis akan mempengaruhi rate DEPUSE ke depan; jurnal historis tetap intact (PSAK 16 par. 51 — revisi estimasi diperlakukan prospektif).'
                        : null),

                TextInput::make('useful_life_rits')
                    ->label('Umur Ekonomis')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix('rit')
                    ->placeholder('mis. 5000 (5rb rit muatan)')
                    ->required(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::PerRit->value)
                    ->visible(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::PerRit->value)
                    ->helperText(fn (?Asset $record): ?string => $record?->hasPostedDepreciationJournal()
                        ? '⚠ Aset ini sudah punya jurnal penyusutan. Mengubah umur ekonomis akan mempengaruhi rate DEPUSE ke depan; jurnal historis tetap intact (PSAK 16 par. 51 — revisi estimasi diperlakukan prospektif).'
                        : null),

                TextInput::make('useful_life_days')
                    ->label('Umur Ekonomis')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.5)
                    ->suffix('hari')
                    ->placeholder('mis. 1825 (5 tahun × 365 hari)')
                    ->required(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::PerDay->value)
                    ->visible(fn (Get $get): bool => $get('depreciation_method') === DepreciationMethod::PerDay->value)
                    ->helperText(fn (?Asset $record): string => $record?->hasPostedDepreciationJournal()
                        ? '⚠ Aset ini sudah punya jurnal penyusutan. Mengubah umur ekonomis akan mempengaruhi rate DEPUSE ke depan; jurnal historis tetap intact (PSAK 16 par. 51 — revisi estimasi diperlakukan prospektif).'
                        : 'Input log bisa half-day (0.5, 1.0, 1.5).'),

                Select::make('account_id')
                    ->label('Akun Aset Tetap')
                    ->relationship(
                        name: 'account',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->where('sub_category', 'aset_tetap')
                            ->postable(),
                    )
                    ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}] {$record->name}")
                    ->searchable()
                    ->required()
                    ->preload()
                    ->helperText('Akun HEADER otomatis disembunyikan — pilih sub-akun spesifik.'),

                Select::make('default_business_unit_id')
                    ->label('Lini Bisnis Default')
                    ->native(false)
                    ->required()
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

    /**
     * Field penentu besar penyusutan terkunci setelah ada jurnal penyusutan
     * (lihat Asset::LOCKED_AFTER_DEPRECIATION) — ditampilkan terkunci di form,
     * bukan baru ditolak saat simpan. Hasil disimpan per record (1 query).
     */
    private static function locked(?Asset $record): bool
    {
        static $cache = [];
        if (! $record?->exists) {
            return false;
        }

        return $cache[$record->getKey()] ??= $record->hasPostedDepreciationJournal();
    }
}
