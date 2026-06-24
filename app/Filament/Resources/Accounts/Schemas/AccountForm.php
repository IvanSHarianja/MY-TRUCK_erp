<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_code')
                    ->label('Akun Induk (opsional)')
                    ->placeholder('— Tidak ada (akun standalone) —')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Account::query()->where('is_active', true);
                        if ($tenant) {
                            $query->where('company_id', $tenant->getKey());
                        }
                        return $query->orderBy('code')->get()
                            ->mapWithKeys(fn ($a) => [$a->code => "[{$a->code}] {$a->name}"])
                            ->toArray();
                    })
                    ->searchable()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        // Auto-suggest child code & inherit kategori dari parent
                        if ($state) {
                            $tenant = Filament::getTenant();
                            $parent = Account::withoutGlobalScopes()
                                ->where('company_id', $tenant?->getKey())
                                ->where('code', $state)
                                ->first();

                            if ($parent && ! $get('code')) {
                                $set('code', Account::suggestChildCode($state, $tenant->getKey()));
                            }

                            if ($parent) {
                                // Inherit category & sub_category dari parent
                                if (! $get('category'))      $set('category', $parent->category);
                                if (! $get('sub_category')) $set('sub_category', $parent->sub_category);
                                if (! $get('normal_balance')) $set('normal_balance', $parent->normal_balance);
                                if (! $get('cash_flow_category')) $set('cash_flow_category', $parent->cash_flow_category);
                            }
                        }
                    })
                    ->helperText('Pilih akun induk untuk menjadikan akun ini sebagai sub-akun. Akun induk otomatis jadi HEADER (tidak bisa di-post langsung).')
                    ->columnSpanFull(),

                TextInput::make('code')
                    ->label('Kode Akun')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('contoh: 111100 atau 111100-01')
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        $tenant = Filament::getTenant();
                        return $rule->where('company_id', $tenant?->getKey());
                    }),

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
