<?php

namespace App\Filament\Resources\BusinessUnits\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BusinessUnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(10)
                    ->placeholder('RENT / ARMD / MATL / BONG')
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        $tenant = Filament::getTenant();
                        return $rule->where('company_id', $tenant?->getKey());
                    })
                    ->validationMessages([
                        'unique' => 'Kode lini bisnis ini sudah dipakai. Pilih kode lain.',
                    ]),

                TextInput::make('name')
                    ->label('Nama Lini Bisnis')
                    ->required()
                    ->maxLength(255),

                TextInput::make('description')
                    ->label('Deskripsi')
                    ->maxLength(255)
                    ->columnSpanFull(),

                ColorPicker::make('color')
                    ->label('Warna Badge')
                    ->default('#3B82F6')
                    ->required(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}
