<?php

namespace App\Filament\Resources\MaterialSales\Schemas;

use App\Models\Account;
use App\Models\Material;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class MaterialSaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Penjualan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sale_number')
                            ->label('No. Penjualan')
                            ->placeholder('Otomatis')
                            ->disabled()
                            ->dehydrated(false),

                        DatePicker::make('sale_date')
                            ->label('Tanggal Penjualan')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Select::make('client_id')
                            ->label('Pelanggan')
                            ->relationship('client', 'name', fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('metode')
                            ->label('Metode Pembayaran')
                            ->required()
                            ->default('tunai')
                            ->options([
                                'tunai'   => '💰 Tunai (langsung kas)',
                                'invoice' => '🧾 Invoice (piutang)',
                            ])
                            ->native(false)
                            ->live(),
                    ]),

                Section::make('Detail Material')
                    ->columns(2)
                    ->schema([
                        Select::make('material_id')
                            ->label('Material')
                            ->required()
                            ->live()
                            ->options(function () {
                                $tenant = Filament::getTenant();
                                $query = Material::query()->where('is_active', true);
                                if ($tenant) {
                                    $query->where('company_id', $tenant->getKey());
                                }
                                return $query->orderBy('code')->get()
                                    ->mapWithKeys(fn ($m) => [
                                        $m->id => "[{$m->code}] {$m->name} (Rp " . number_format($m->harga_per_satuan, 0, ',', '.') . "/{$m->satuan})",
                                    ])
                                    ->toArray();
                            })
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state) {
                                    $mat = Material::find($state);
                                    if ($mat) {
                                        $set('harga_satuan', (float) $mat->harga_per_satuan);
                                    }
                                }
                            })
                            ->searchable(),

                        TextInput::make('volume')
                            ->label('Volume')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->live(onBlur: true)
                            ->suffix(fn (Get $get) => $get('material_id')
                                ? optional(Material::find($get('material_id')))->satuan ?? 'unit'
                                : 'unit'),

                        TextInput::make('harga_satuan')
                            ->label('Harga per Satuan (Rp)')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->helperText('Otomatis terisi dari master material, bisa di-override'),

                        Placeholder::make('total_preview')
                            ->label('Total')
                            ->content(function (Get $get): HtmlString {
                                $vol  = (float) ($get('volume') ?? 0);
                                $harga = (float) ($get('harga_satuan') ?? 0);
                                $total = $vol * $harga;
                                return new HtmlString(
                                    '<div style="font-size: 18px; font-weight: 700; color: var(--primary-600);">'
                                    . 'Rp ' . number_format($total, 0, ',', '.')
                                    . '</div>'
                                );
                            }),
                    ]),

                Section::make('Akun Penerimaan (jika tunai)')
                    ->visible(fn (Get $get): bool => $get('metode') === 'tunai')
                    ->schema([
                        Select::make('cash_account_id')
                            ->label('Diterima ke Akun')
                            ->options(function () {
                                $tenant = Filament::getTenant();
                                $query = Account::query()
                                    ->where('is_active', true)
                                    ->where('sub_category', 'aset_lancar')
                                    ->where('code', 'like', '111%');
                                if ($tenant) {
                                    $query->where('company_id', $tenant->getKey());
                                }
                                return $query->orderBy('code')->get()
                                    ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText('Kosongkan untuk default ke 111100 Kas dan Bank'),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Catatan')
                    ->visible(fn (Get $get): bool => $get('metode') === 'invoice')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2),
                    ]),
            ]);
    }
}
