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
                    ->numeric()
                    ->default(0)
                    ->prefix('Rp')
                    ->helperText('Harga jual default ke klien.'),

                TextInput::make('harga_pokok')
                    ->label('Harga Pokok per Satuan (HPP)')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('Rp')
                    ->helperText('Biaya modal per satuan. Dipakai auto-post jurnal HPP setiap penjualan. Isi 0 untuk skip HPP posting (laba kotor jadi = harga jual).'),

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
