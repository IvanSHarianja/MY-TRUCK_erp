<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Enums\MaintenanceType;
use App\Models\AssetMaintenanceLog;
use App\Models\Material;
use App\Models\Vendor;
use App\Services\Accounting\MaintenanceService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class MaintenanceLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceLogs';

    protected static ?string $title = 'Riwayat Pemeliharaan';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('maintenance_date')
                    ->label('Tanggal Service')
                    ->required()
                    ->default(now())
                    ->native(false),

                Select::make('type')
                    ->label('Jenis Pemeliharaan')
                    ->required()
                    ->native(false)
                    ->options(collect(MaintenanceType::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->label()])
                        ->all())
                    ->default(MaintenanceType::ServiceRutin->value),

                Textarea::make('description')
                    ->label('Deskripsi Pekerjaan')
                    ->required()
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('contoh: Ganti oli mesin + filter oli')
                    ->columnSpanFull(),

                Select::make('vendor_id')
                    ->label('Bengkel / Vendor')
                    ->native(false)
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $q = Vendor::query()->where('is_active', true);
                        if ($tenant) {
                            $q->where('company_id', $tenant->getKey());
                        }
                        return $q->orderBy('name')->get()
                            ->mapWithKeys(fn ($v) => [$v->id => "[{$v->code}] {$v->name}"])
                            ->toArray();
                    })
                    ->searchable()
                    ->placeholder('Pilih vendor (opsional)'),

                TextInput::make('cost')
                    ->label('Biaya Jasa / Dibayar Tunai')
                    ->required()
                    ->default(0)
                    ->rupiah()
                    ->helperText('Upah montir, jasa bengkel, dan sparepart yang dibeli langsung di bengkel. '
                        . 'Sparepart yang diambil dari stok diisi di bawah — jangan dihitung dua kali. '
                        . 'Isi 0 kalau servis gratis/garansi.'),

                Repeater::make('items')
                    ->label('Sparepart dari Stok')
                    ->addActionLabel('Tambah sparepart')
                    ->default([])
                    ->columns(2)
                    ->columnSpanFull()
                    ->helperText('Hanya material berjenis Sparepart. Stok otomatis berkurang dan '
                        . 'nilainya dihitung dari MAC (harga pokok rata-rata).')
                    ->schema([
                        Select::make('material_id')
                            ->label('Sparepart')
                            ->required()
                            ->live()
                            ->native(false)
                            ->searchable()
                            ->options(function (): array {
                                $tenant = Filament::getTenant();

                                return Material::query()
                                    ->sparepart()
                                    ->where('is_active', true)
                                    ->when($tenant, fn ($q) => $q->where('company_id', $tenant->getKey()))
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn (Material $m) => [
                                        $m->id => sprintf(
                                            '[%s] %s — stok %s %s',
                                            $m->code,
                                            $m->name,
                                            rtrim(rtrim(number_format((float) $m->current_stock, 2, ',', '.'), '0'), ','),
                                            $m->satuan,
                                        ),
                                    ])
                                    ->all();
                            }),

                        TextInput::make('qty')
                            ->label('Jumlah Dipakai')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->live(onBlur: true)
                            ->suffix(fn (Get $get): string => Material::find($get('material_id'))?->satuan ?? '')
                            // Peringatan dini — service tetap menolak saat simpan.
                            ->helperText(function (Get $get): ?HtmlString {
                                $material = Material::find($get('material_id'));
                                $qty = (float) $get('qty');
                                if (! $material || $qty <= 0) {
                                    return null;
                                }

                                $stok = (float) $material->current_stock;
                                $nilai = round($qty * (float) $material->current_mac);
                                $fmt = fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');

                                if ($qty > $stok) {
                                    return new HtmlString(
                                        '<span style="color:#dc2626;font-weight:600;">Stok tidak cukup — tersedia '
                                        . $fmt($stok) . ' ' . $material->satuan . '. Catat pembeliannya dulu.</span>'
                                    );
                                }

                                return new HtmlString(
                                    'Nilai: Rp ' . number_format($nilai, 0, ',', '.')
                                    . ' — sisa stok setelah dipakai: ' . $fmt($stok - $qty) . ' ' . $material->satuan
                                );
                            }),
                    ]),

                TextInput::make('hm_saat_service')
                    ->label('HM Saat Service')
                    ->numeric()
                    ->step(0.1)
                    ->suffix('jam'),

                TextInput::make('next_service_hm')
                    ->label('Target HM Service Berikutnya')
                    ->numeric()
                    ->step(0.1)
                    ->suffix('jam')
                    ->helperText('Untuk preventive alert'),

                DatePicker::make('next_service_date')
                    ->label('Target Tanggal Service Berikutnya')
                    ->native(false)
                    ->helperText('Alternatif alert berdasar tanggal'),

                TextInput::make('photo_url')
                    ->label('URL Foto / Nota (opsional)')
                    ->url()
                    ->maxLength(500)
                    ->columnSpanFull(),

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

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('items.material'))
            ->recordTitleAttribute('description')
            ->defaultSort('maintenance_date', 'desc')
            ->columns([
                TextColumn::make('maintenance_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof MaintenanceType ? $state->label() : $state)
                    ->color(fn ($state) => $state instanceof MaintenanceType ? $state->color() : 'gray'),

                TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->description),

                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->placeholder('—')
                    ->limit(20)
                    ->toggleable(),

                TextColumn::make('cost')
                    ->label('Biaya Jasa')
                    ->money('IDR', 0)
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('sparepart_cost')
                    ->label('Sparepart')
                    ->state(fn (AssetMaintenanceLog $record): float => $record->sparepartCost())
                    ->money('IDR', 0)
                    ->alignEnd()
                    ->tooltip(fn (AssetMaintenanceLog $record): ?string => $record->items->isEmpty()
                        ? null
                        : $record->items
                            ->map(fn ($i) => optional($i->material)->name . ' × ' . rtrim(rtrim(number_format((float) $i->qty, 2, ',', '.'), '0'), ','))
                            ->implode(', '))
                    ->toggleable(),

                TextColumn::make('total_cost')
                    ->label('Total Biaya')
                    ->state(fn (AssetMaintenanceLog $record): float => $record->totalCost())
                    ->money('IDR', 0)
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('hm_saat_service')
                    ->label('HM')
                    ->numeric(decimalPlaces: 1)
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('journal_entry_id')
                    ->label('Jurnal')
                    ->boolean()
                    ->tooltip(fn ($record) => $record->journal_entry_id ? 'Jurnal terposting' : 'Belum ada jurnal (cost=0?)'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Catat Pemeliharaan')
                    ->using(function (array $data, RelationManager $livewire): \App\Models\AssetMaintenanceLog {
                        // Panggil service supaya jurnal auto-terpost — bukan Model::create biasa.
                        $data['asset_id'] = $livewire->getOwnerRecord()->id;
                        $log = app(MaintenanceService::class)->log($data);

                        Notification::make()
                            ->title($log->journal_entry_id
                                ? '✅ Maintenance tercatat + jurnal terpost'
                                : '✅ Maintenance tercatat (cost 0, tanpa jurnal)')
                            ->success()
                            ->send();

                        return $log;
                    }),
            ])
            ->recordActions([
                Action::make('lihat_bukti')->liatBukti(),

                EditAction::make()
                    // Isi ulang repeater dari baris sparepart yang tersimpan.
                    ->fillForm(fn (AssetMaintenanceLog $record): array => array_merge(
                        $record->attributesToArray(),
                        [
                            'items' => $record->items
                                ->map(fn ($i): array => [
                                    'material_id' => $i->material_id,
                                    'qty'         => (float) $i->qty,
                                ])
                                ->all(),
                        ],
                    ))
                    // Lewat service: stok dikembalikan dulu, lalu dipakai ulang
                    // sesuai isian baru. EditAction biasa hanya menyimpan kolom log.
                    ->using(fn (AssetMaintenanceLog $record, array $data): AssetMaintenanceLog => app(MaintenanceService::class)
                        ->updateLog($record, $data)),

                DeleteAction::make(),
            ]);
    }
}
