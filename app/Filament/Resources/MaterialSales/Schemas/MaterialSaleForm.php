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
                                // Sparepart dipakai sendiri saat servis — tidak dijual ke klien.
                                $query = Material::query()->jual()->where('is_active', true);
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

                        // Jual per satuan material (M³/ton) atau per RIT (masukan 2026-11-18).
                        Select::make('satuan_jual')
                            ->label('Dijual per')
                            ->native(false)
                            ->default('satuan')
                            ->required()
                            ->options(['satuan' => 'Satuan material (M³ / ton)', 'rit' => 'Rit (per truk)'])
                            ->live(),

                        TextInput::make('jumlah_rit')
                            ->label('Jumlah Rit')
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->live(onBlur: true)
                            ->required(fn (Get $get): bool => $get('satuan_jual') === 'rit')
                            ->helperText(fn (Get $get): string => $get('satuan_jual') === 'rit'
                                ? 'Dasar tagihan (rit × harga per rit) dan ongkos supir & BM per rit.'
                                : 'Opsional — isi bila ada ongkos supir / BM per rit.'),

                        TextInput::make('volume')
                            ->label(fn (Get $get): string => $get('satuan_jual') === 'rit' ? 'Volume Keluar dari Stok' : 'Volume')
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
                            ->label(fn (Get $get): string => $get('satuan_jual') === 'rit' ? 'Harga per Rit (Rp)' : 'Harga per Satuan (Rp)')
                            ->required()
                            ->rupiah()
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => $get('satuan_jual') === 'rit'
                                ? 'Harga satu rit (satu truk) sampai lokasi.'
                                : 'Otomatis terisi dari master material, bisa di-override'),

                        Placeholder::make('total_preview')
                            ->label('Total')
                            ->content(function (Get $get): HtmlString {
                                $qty   = $get('satuan_jual') === 'rit' ? (float) ($get('jumlah_rit') ?? 0) : (float) ($get('volume') ?? 0);
                                $harga = self::money($get('harga_satuan'));
                                $total = $qty * $harga;
                                return new HtmlString(
                                    '<div style="font-size: 18px; font-weight: 700; color: var(--primary-600);">'
                                    . 'Rp ' . number_format($total, 0, ',', '.')
                                    . '</div>'
                                );
                            }),
                    ]),

                // Biaya armada per penjualan (masukan atasan 2026-11-18): ongkos supir
                // per rit, UM supir, BBM, BM per rit, portal, lain-lain. Dijurnal
                // Dr beban / Cr kas, ditandai truk → laporan Margin Penjualan.
                Section::make('Pengiriman (Armada)')
                    ->description('Biaya mengantar material. Dibayar tunai dari kas yang dipilih. Kosongkan bila klien mengambil sendiri.')
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        Select::make('asset_id')
                            ->label('Truk')
                            ->native(false)
                            ->searchable()
                            ->options(fn () => \App\Models\Asset::query()
                                ->whereIn('type', ['dump_truck', 'kendaraan_operasional'])
                                ->orderBy('asset_code')->get()
                                ->mapWithKeys(fn ($a) => [$a->id => "[{$a->asset_code}] {$a->name}"])->all()),

                        Select::make('driver_id')
                            ->label('Supir')
                            ->native(false)
                            ->searchable()
                            ->options(fn () => \App\Models\Employee::query()->where('is_active', true)->where('position', 'driver')
                                ->orderBy('name')->get()
                                ->mapWithKeys(fn ($e) => [$e->id => "[{$e->employee_id}] {$e->name}"])->all()),

                        Select::make('biaya_cash_account_id')
                            ->label('Dibayar dari Kas')
                            ->native(false)
                            ->options(function () {
                                $tenant = Filament::getTenant();

                                return $tenant ? Account::cashAccounts($tenant->getKey())
                                    ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])->all() : [];
                            })
                            ->helperText('Kosong = sama dengan akun penerimaan / kas utama.'),

                        TextInput::make('ongkos_supir_per_rit')->label('Ongkos Supir per Rit')->rupiah()->live(onBlur: true),
                        TextInput::make('bm_per_rit')->label('BM (Bongkar Muat) per Rit')->rupiah()->live(onBlur: true),
                        TextInput::make('uang_makan_supir')->label('Uang Makan Supir')->rupiah()->live(onBlur: true),
                        TextInput::make('biaya_bbm')->label('BBM')->rupiah()->live(onBlur: true),
                        TextInput::make('biaya_portal')->label('Portal')->rupiah()->live(onBlur: true),
                        TextInput::make('biaya_lain')->label('Lain-lain')->rupiah()->live(onBlur: true),
                        TextInput::make('biaya_lain_ket')->label('Keterangan Lain-lain')->maxLength(150)->columnSpan(2),

                        Placeholder::make('margin_preview')
                            ->label('Perkiraan Margin')
                            ->columnSpanFull()
                            ->content(function (Get $get): HtmlString {
                                $rp    = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
                                $rit   = (float) ($get('jumlah_rit') ?? 0);
                                $qty   = $get('satuan_jual') === 'rit' ? $rit : (float) ($get('volume') ?? 0);
                                $jual  = $qty * self::money($get('harga_satuan'));
                                $kirim = $rit * (self::money($get('ongkos_supir_per_rit')) + self::money($get('bm_per_rit')))
                                    + self::money($get('uang_makan_supir')) + self::money($get('biaya_bbm'))
                                    + self::money($get('biaya_portal')) + self::money($get('biaya_lain'));
                                $mat   = $get('material_id') ? Material::find($get('material_id')) : null;
                                $hpp   = (float) ($get('volume') ?? 0) * (float) ($mat?->current_mac ?: $mat?->harga_pokok ?: 0);
                                $margin = $jual - $hpp - $kirim;
                                $pct   = $jual > 0 ? round($margin / $jual * 100, 1) : 0;

                                return new HtmlString(sprintf(
                                    '<div style="line-height:1.8">Penjualan %s − HPP %s − biaya kirim %s = <strong style="color:%s">%s (%s%%)</strong></div>',
                                    $rp($jual), $rp($hpp), $rp($kirim), $margin < 0 ? '#dc2626' : '#059669', $rp($margin), $pct,
                                ));
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

    /** Nilai isian rupiah (bisa "1.500.000" dari mask) → angka. */
    private static function money(mixed $value): float
    {
        return (float) (app(\App\Services\Import\AssetImportService::class)->money($value) ?? 0);
    }
}
