<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode Vendor')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('VND-001')
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        $tenant = Filament::getTenant();
                        return $rule->where('company_id', $tenant?->getKey());
                    })
                    ->validationMessages([
                        'unique' => 'Kode vendor ini sudah dipakai. Pilih kode lain.',
                    ]),

                TextInput::make('name')
                    ->label('Nama Vendor')
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->label('Jenis Vendor')
                    ->options([
                        'kuari'     => 'Kuari',
                        'bbm'       => 'BBM',
                        'sparepart' => 'Sparepart',
                        'jasa'      => 'Jasa',
                        'leasing'   => 'Leasing',
                        'lainnya'   => 'Lainnya',
                    ])
                    ->default('lainnya')
                    ->required()
                    ->native(false),

                TextInput::make('contact_person')
                    ->label('Contact Person')
                    ->maxLength(255),

                TextInput::make('phone')
                    ->label('Telepon')
                    ->tel()
                    ->maxLength(30),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),

                TextInput::make('npwp')
                    ->label('NPWP')
                    ->maxLength(30),

                Textarea::make('address')
                    ->label('Alamat')
                    ->rows(2)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}
