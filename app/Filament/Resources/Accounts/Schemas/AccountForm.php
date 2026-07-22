<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountRole;
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
                                // Sprint 2.5: inherit role juga — sub-akun kas biasanya
                                // pakai role sama dengan parent (Kas BCA & Kas Mandiri
                                // sama-sama role 'cash').
                                if (! $get('role') && $parent->role) {
                                    $set('role', $parent->role instanceof AccountRole ? $parent->role->value : $parent->role);
                                }
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
                    ->maxLength(255)
                    ->live(onBlur: true)
                    // Auto-suggest role dari nama akun (Opsi C — Keyword Suggestion).
                    // User ketik "Bank Mandiri" → role auto-set 'cash'.
                    // Cuma set kalau role masih kosong — respect user override.
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        if (! $state || $get('role')) return;

                        $suggested = AccountRole::suggestFromName($state);
                        if (! $suggested) return;

                        // Cek: kalau category sudah dipilih, hanya suggest kalau
                        // role match dengan category (hindari suggest role aset
                        // saat user sudah pilih category ekuitas).
                        $category = $get('category');
                        if ($category && $suggested->categoryOf() !== $category) {
                            return;
                        }

                        $set('role', $suggested->value);
                        // Trigger derive dari role (isi sub_category + cash_flow_category)
                        $set('sub_category', $suggested->defaultSubCategory());
                        $set('cash_flow_category', $suggested->defaultCashFlow());
                        if (! $category) {
                            $set('category', $suggested->categoryOf());
                        }
                    })
                    ->helperText('💡 Ketik nama akun (mis. "Bank Mandiri" atau "Setoran Modal"). Sistem otomatis suggest role kalau ada keyword yang cocok.'),

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
                    ->native(false)
                    ->live()
                    // Auto-fill sub_category & cash_flow_category kalau kosong.
                    // User boleh override manual — cuma isi kalau field masih kosong.
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        if (! $state) return;

                        if (empty($get('sub_category'))) {
                            $set('sub_category', match ($state) {
                                'aset'       => 'aset_lancar',
                                'kewajiban'  => 'kewajiban_lancar',
                                'ekuitas'    => 'ekuitas',
                                'pendapatan' => 'pendapatan_usaha',
                                'beban'      => 'beban_operasional',
                                'penutup'    => 'penutup',
                                default      => null,
                            });
                        }

                        if (empty($get('cash_flow_category'))) {
                            $set('cash_flow_category', match ($state) {
                                'aset', 'kewajiban', 'pendapatan', 'beban' => 'operasi',
                                'ekuitas'                                    => 'pendanaan',
                                'penutup'                                    => 'non_kas',
                                default                                       => null,
                            });
                        }
                    }),

                TextInput::make('sub_category')
                    ->label('Sub-Kategori')
                    ->placeholder('contoh: aset_lancar, beban_hpp')
                    ->helperText('Otomatis terisi dari Kategori/Role. Boleh diubah manual bila perlu (mis. aset_lancar → aset_tetap untuk aset armada).'),

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

                Select::make('role')
                    ->label('Peran Akun (Role)')
                    // Opsi C — Filter dinamis by category. User pilih category dulu →
                    // dropdown role hanya tampilkan role yang relevan (mis. aset →
                    // cash, receivable, fixed_asset_*, dll, bukan 44 role campur).
                    // Kalau category belum dipilih, tampilkan semua (grouped).
                    ->options(function (Get $get): array {
                        $category = $get('category');
                        if (! $category) {
                            return AccountRole::optionsGrouped();
                        }
                        return AccountRole::applicableRolesForCategory($category);
                    })
                    ->searchable()
                    ->native(false)
                    ->live()
                    // Auto-override sub_category & cash_flow_category ke value tepat
                    // saat user pilih role. Overwrite meskipun sub_category sudah terisi
                    // dari kategori — karena role lebih spesifik (mis. FixedAssetArmada
                    // sub_category harus 'aset_tetap' bukan 'aset_lancar' default aset).
                    ->afterStateUpdated(function ($state, Set $set) {
                        if (! $state) return;

                        $role = AccountRole::tryFrom((string) $state);
                        if (! $role) return;

                        $set('sub_category', $role->defaultSubCategory());
                        $set('cash_flow_category', $role->defaultCashFlow());
                    })
                    ->helperText(new \Illuminate\Support\HtmlString(
                        '<strong>Filter otomatis berdasarkan Kategori</strong>. '
                        . 'Cukup pilih Kategori dulu, dropdown ini otomatis batasi ke role yang relevan. '
                        . 'Sistem juga <em>auto-suggest role</em> saat Anda ketik nama akun (mis. ketik "Bank Mandiri" → role Kas auto-set).'
                    ))
                    ->columnSpanFull(),

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
