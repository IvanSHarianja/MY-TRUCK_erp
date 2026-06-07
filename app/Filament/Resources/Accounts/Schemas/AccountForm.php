<?php

namespace App\Filament\Resources\Accounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode Akun')
                    ->required()
                    ->maxLength(10)
                    ->placeholder('contoh: 111100'),

                TextInput::make('name')
                    ->label('Nama Akun')
                    ->required()
                    ->maxLength(255),

                Select::make('category')
                    ->label('Kategori')
                    ->options([
                        'aset'       => 'Aset',
                        'kewajiban'  => 'Kewajiban',
                        'ekuitas'    => 'Ekuitas',
                        'pendapatan' => 'Pendapatan',
                        'beban'      => 'Beban',
                        'penutup'    => 'Akun Penutup',
                    ])
                    ->required()
                    ->native(false),

                TextInput::make('sub_category')
                    ->label('Sub-Kategori')
                    ->placeholder('contoh: aset_lancar, beban_hpp'),

                TextInput::make('parent_code')
                    ->label('Kode Induk (opsional)')
                    ->maxLength(10),

                Select::make('normal_balance')
                    ->label('Saldo Normal')
                    ->options(['debit' => 'Debit', 'kredit' => 'Kredit'])
                    ->required()
                    ->native(false),

                Select::make('cash_flow_category')
                    ->label('Kategori Arus Kas')
                    ->options([
                        'operasi'   => 'Operasi',
                        'investasi' => 'Investasi',
                        'pendanaan' => 'Pendanaan',
                        'non_kas'   => 'Non-Kas',
                    ])
                    ->native(false),

                Select::make('tax_type')
                    ->label('Jenis Pajak')
                    ->options([
                        'non_pajak'    => 'Non-Pajak',
                        'ppn'          => 'PPN',
                        'pph_21'       => 'PPh 21',
                        'pph_23'       => 'PPh 23',
                        'ppn_pph_23'   => 'PPN + PPh 23',
                    ])
                    ->default('non_pajak')
                    ->required()
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
