<?php

namespace App\Filament\Resources\Materials\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MaterialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode Material')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('contoh: MAT-001')
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        $tenant = Filament::getTenant();
                        return $rule->where('company_id', $tenant?->getKey());
                    })
                    ->validationMessages([
                        'unique' => 'Kode material ini sudah dipakai. Pilih kode lain.',
                    ]),

                TextInput::make('name')
                    ->label('Nama Material')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('contoh: Sirtu, Pasir Urug, Batu Belah'),

                TextInput::make('harga_per_satuan')
                    ->label('Harga Jual per Satuan')
                    ->required()
                    ->default(0)
                    ->rupiah()
                    ->helperText('Harga jual default ke klien.'),

                TextInput::make('current_mac')
                    ->label('Harga Pokok per Satuan (MAC)')
                    // BIZ-01: HPP sekarang auto-computed dari Moving Average Cost
                    // hasil pembelian material. Field ini DISPLAY ONLY — bukan input.
                    // Untuk mengubah, user harus input pembelian material baru.
                    ->disabled()
                    ->dehydrated(false)  // jangan submit ke DB (readonly display)
                    ->prefix('Rp')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 0, ',', '.') : '0')
                    ->helperText('Auto-hitung dari pembelian material (Moving Average Cost). Tidak bisa diedit manual — untuk mengubah, input Pembelian Material baru. Kalau masih 0, artinya belum pernah ada pembelian untuk material ini.'),

                Select::make('satuan')
                    ->label('Satuan')
                    ->required()
                    ->default('m3')
                    ->options([
                        'm3'  => 'm³ (kubik)',
                        'm2'  => 'm² (luas)',
                        'ton' => 'Ton',
                        'kg'  => 'Kilogram',
                        'rit' => 'Rit / Truk',
                        'pcs' => 'Pcs / Unit',
                    ])
                    ->native(false),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
