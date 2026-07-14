<?php

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('employee_id')
                    ->label('NIK / ID Karyawan')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('EMP-001')
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        $tenant = Filament::getTenant();
                        return $rule->where('company_id', $tenant?->getKey());
                    })
                    ->validationMessages([
                        'unique' => 'NIK/ID karyawan ini sudah dipakai. Pilih ID lain.',
                    ]),

                TextInput::make('name')
                    ->label('Nama Lengkap')
                    ->required()
                    ->maxLength(255),

                Select::make('position')
                    ->label('Posisi')
                    ->options([
                        'driver'   => 'Driver',
                        'operator' => 'Operator',
                        'mandor'   => 'Mandor',
                        'admin'    => 'Admin',
                        'mekanik'  => 'Mekanik',
                        'lainnya'  => 'Lainnya',
                    ])
                    ->default('driver')
                    ->required()
                    ->native(false),

                Select::make('assigned_asset_id')
                    ->label('Aset yang Ditugaskan')
                    ->relationship('assignedAsset', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->asset_code}] {$record->name}")
                    ->searchable()
                    ->preload()
                    ->nullable(),

                DatePicker::make('join_date')
                    ->label('Tanggal Masuk')
                    ->native(false),

                TextInput::make('phone')
                    ->label('Telepon')
                    ->tel()
                    ->maxLength(30),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}
