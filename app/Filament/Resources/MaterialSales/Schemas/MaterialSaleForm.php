<?php

namespace App\Filament\Resources\MaterialSales\Schemas;

use App\Models\Account;
use App\Models\Material;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
                                    // BUG-33: explicit tenant scope (defense-in-depth)
                                    $tenant = Filament::getTenant();
                                    $mat = Material::query()
                                        ->when($tenant, fn ($q) => $q->where('company_id', $tenant->getKey()))
                                        ->find($state);
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
                            // BIZ-01 guard: sekali sale sudah punya stock movement,
                            // volume tidak boleh diubah — kalau salah, harus void
                            // invoice-nya dulu (auto restore stock via observer),
                            // baru create sale baru dengan volume yang benar.
                            // Alasan: edit volume mid-way = stock drift + jurnal HPP
                            // beda dengan sale actual.
                            ->disabled(fn ($record) => $record !== null
                                && \App\Models\MaterialStockMovement::withoutGlobalScopes()
                                    ->where('source_type', \App\Models\MaterialSale::class)
                                    ->where('source_id', $record->id)
                                    ->exists())
                            ->hint(fn ($record): ?string => $record !== null
                                && \App\Models\MaterialStockMovement::withoutGlobalScopes()
                                    ->where('source_type', \App\Models\MaterialSale::class)
                                    ->where('source_id', $record->id)
                                    ->exists()
                                ? '🔒 Volume tidak bisa diubah — void invoice ini dulu untuk restore stock, baru buat sale baru.'
                                : null)
                            ->hintColor('warning')
                            ->suffix(function (Get $get) {
                                if (! $get('material_id')) return 'unit';
                                // BUG-33: explicit tenant scope
                                $tenant = Filament::getTenant();
                                $mat = Material::query()
                                    ->when($tenant, fn ($q) => $q->where('company_id', $tenant->getKey()))
                                    ->find($get('material_id'));
                                return optional($mat)->satuan ?? 'unit';
                            })
                            // BIZ-01: Real-time stock check helper — user tahu langsung
                            // apakah qty melebihi stok sebelum submit.
                            ->helperText(function (Get $get): ?HtmlString {
                                $matId = $get('material_id');
                                if (! $matId) return null;

                                $tenant = Filament::getTenant();
                                $mat = Material::query()
                                    ->when($tenant, fn ($q) => $q->where('company_id', $tenant->getKey()))
                                    ->find($matId);
                                if (! $mat) return null;

                                $stock = (float) $mat->current_stock;
                                $vol   = (float) ($get('volume') ?? 0);
                                $hasHistory = \App\Models\MaterialStockMovement::withoutGlobalScopes()
                                    ->where('material_id', $matId)
                                    ->exists();

                                if (! $hasHistory) {
                                    return new HtmlString(
                                        '<span style="color:#64748b;">📦 Material legacy — belum ada riwayat pembelian. Sale tetap boleh, HPP pakai harga_pokok statis.</span>'
                                    );
                                }

                                $stockLabel = rtrim(rtrim(number_format($stock, 2, ',', '.'), '0'), ',');

                                if ($vol > 0 && $vol > $stock) {
                                    return new HtmlString(sprintf(
                                        '<span style="color:#dc2626; font-weight:600;">⚠ Stok tidak cukup — tersedia %s %s, diminta %s %s. Input Pembelian Material dulu.</span>',
                                        e($stockLabel), e($mat->satuan),
                                        rtrim(rtrim(number_format($vol, 2, ',', '.'), '0'), ','), e($mat->satuan),
                                    ));
                                }

                                if ($vol > 0 && $vol <= $stock) {
                                    $sisa = $stock - $vol;
                                    $sisaLabel = rtrim(rtrim(number_format($sisa, 2, ',', '.'), '0'), ',');
                                    return new HtmlString(sprintf(
                                        '<span style="color:#059669;">✓ Stok cukup — tersedia %s %s. Setelah sale ini: sisa %s %s.</span>',
                                        e($stockLabel), e($mat->satuan),
                                        e($sisaLabel), e($mat->satuan),
                                    ));
                                }

                                return new HtmlString(sprintf(
                                    '<span style="color:#0284c7;">📊 Stok tersedia: <strong>%s %s</strong> · MAC: Rp %s</span>',
                                    e($stockLabel), e($mat->satuan),
                                    number_format((float) $mat->current_mac, 0, ',', '.'),
                                ));
                            }),

                        TextInput::make('harga_satuan')
                            ->label('Harga per Satuan (Rp)')
                            ->required()
                            ->rupiah()
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
                                if (! $tenant) return [];
                                return Account::cashAccounts($tenant->getKey())
                                    ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText(function (): string {
                                $tenant = Filament::getTenant();
                                if (! $tenant) return '';
                                return Account::cashAccounts($tenant->getKey())->isEmpty()
                                    ? '⚠️ Belum ada sub-akun kas/bank. Buat akun parent kas (mis. [111100] Kas dan Bank) di Master Data → Daftar Akun, lalu tambah sub-akun spesifik di bawahnya (mis. Kas BCA, Kas Mandiri).'
                                    : 'Pilih sub-akun spesifik bank/kas (BCA / Mandiri / Kas Tunai / dll). Akun HEADER dan akun tanpa parent tidak muncul.';
                            }),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),

                        FileUpload::make('bukti_tf_path')
                            ->buktiTf()
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
