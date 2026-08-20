<?php

namespace App\Filament\Resources\MaterialPurchases\Schemas;

use App\Models\Account;
use App\Models\Material;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class MaterialPurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('purchase_date')
                    ->label('Tanggal Pembelian')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->columnSpan(1),

                Select::make('material_id')
                    ->label('Material')
                    ->required()
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        return Material::query()
                            ->where('is_active', true)
                            ->when($tenant, fn ($q) => $q->where('company_id', $tenant->getKey()))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($m) => [$m->id => "[{$m->code}] {$m->name} ({$m->satuan})"])
                            ->toArray();
                    })
                    ->searchable()
                    ->live()
                    ->columnSpan(1),

                Select::make('vendor_id')
                    ->label('Vendor / Pemasok')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        return Vendor::query()
                            ->where('is_active', true)
                            ->when($tenant, fn ($q) => $q->where('company_id', $tenant->getKey()))
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($v) => [$v->id => "[{$v->code}] {$v->name}"])
                            ->toArray();
                    })
                    ->searchable()
                    ->helperText('Pilih vendor (opsional untuk tunai, wajib untuk kredit).')
                    ->columnSpan(1),

                TextInput::make('qty')
                    ->label('Qty')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0.01)
                    ->required()
                    ->live(onBlur: true)
                    ->suffix(fn (Get $get): string => Material::find($get('material_id'))?->satuan ?? '')
                    ->columnSpan(1),

                TextInput::make('unit_price')
                    ->label('Harga per Unit (Rp)')
                    ->required()
                    ->rupiah()
                    ->live(onBlur: true)
                    ->columnSpan(1),

                Placeholder::make('total_preview')
                    ->label('Total Pembelian')
                    ->content(function (Get $get): HtmlString {
                        $qty   = (float) ($get('qty') ?? 0);
                        $price = (float) ($get('unit_price') ?? 0);
                        $total = $qty * $price;
                        return new HtmlString(sprintf(
                            '<span style="display:inline-block; font-size:18px; font-weight:700; color:#0f172a; background:#e0f2fe; padding:8px 14px; border-radius:8px;">Rp %s</span>',
                            number_format($total, 0, ',', '.'),
                        ));
                    })
                    ->columnSpan(1),

                Select::make('payment_method')
                    ->label('Metode Pembayaran')
                    ->required()
                    ->native(false)
                    ->default('tunai')
                    ->options([
                        'tunai'  => 'Tunai (langsung bayar dari Kas)',
                        'kredit' => 'Kredit (jadi Utang ke Vendor)',
                    ])
                    ->live()
                    ->columnSpan(1),

                Select::make('cash_account_id')
                    ->label('Akun Kas (jika tunai)')
                    ->options(fn () => Account::cashAccounts(Filament::getTenant()?->getKey() ?? 0)
                        ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])
                        ->toArray())
                    ->searchable()
                    ->helperText('Kosongkan → pakai default akun Kas.')
                    ->visible(fn (Get $get) => $get('payment_method') === 'tunai')
                    ->columnSpan(1),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),

                FileUpload::make('bukti_tf_path')
                    ->buktiTf()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
