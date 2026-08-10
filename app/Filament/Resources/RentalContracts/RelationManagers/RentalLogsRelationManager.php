<?php

namespace App\Filament\Resources\RentalContracts\RelationManagers;

use App\Models\Employee;
use App\Models\RentalLog;
use App\Services\Accounting\OperationalCostService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class RentalLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'rentalLogs';

    protected static ?string $title = 'Log Jam Kerja Harian (HM)';

    public function form(Schema $schema): Schema
    {
        // BUG-HM-OVERLAP: capture asset_id kontrak untuk validator overlap.
        // Kontrak = 1 aset (asset_id di RentalContract), semua log kontrak ini
        // wajib punya asset_id sama.
        $ownerRecord = $this->getOwnerRecord();
        $assetId = $ownerRecord?->asset_id;

        return $schema
            ->components([
                DatePicker::make('log_date')
                    ->label('Tanggal')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->live(onBlur: true)  // trigger revalidate hm_awal saat tanggal berubah
                    ->columnSpan(1),

                Select::make('operator_id')
                    ->label('Operator')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Employee::query()
                            ->where('is_active', true)
                            ->whereIn('position', ['operator', 'driver']);
                        if ($tenant) {
                            $query->where('company_id', $tenant->getKey());
                        }
                        return $query->orderBy('name')->get()
                            ->mapWithKeys(fn ($e) => [$e->id => "[{$e->employee_id}] {$e->name}"])
                            ->toArray();
                    })
                    ->searchable()
                    ->columnSpan(1),

                TextInput::make('hm_awal')
                    ->label('HM Awal (Pagi)')
                    ->required()
                    ->numeric()
                    ->step(0.1)
                    ->placeholder('contoh: 4864.0')
                    ->live(onBlur: true)
                    // BUG-34: recalc jam_kerja saat hm_awal berubah juga
                    // (sebelumnya cuma di hm_akhir).
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        $awal = (float) ($state ?? 0);
                        $akhir = (float) ($get('hm_akhir') ?? 0);
                        if ($akhir > $awal) {
                            $set('jam_kerja', round($akhir - $awal, 2));
                        }
                    })
                    // BUG-HM-OVERLAP: HM meter aset selalu naik seiring waktu.
                    // Log baru harus punya HM Awal ≥ HM Akhir tertinggi dari log
                    // aset ini yang tanggalnya ≤ tanggal log baru. Kalau lebih
                    // rendah, artinya user mengklaim jam yang sudah dihitung log
                    // sebelumnya (double-billing).
                    ->rule(static function (Get $get, ?RentalLog $record) use ($assetId): \Closure {
                        return static function (string $attribute, $value, \Closure $fail) use ($get, $record, $assetId): void {
                            if ($value === null || $value === '' || ! $assetId) {
                                return;
                            }
                            $newAwal = (float) $value;
                            $newDate = $get('log_date');
                            if (! $newDate) {
                                return; // biarkan required log_date handle
                            }

                            $q = RentalLog::withoutGlobalScopes()
                                ->where('asset_id', $assetId)
                                ->where('log_date', '<=', $newDate);
                            if ($record?->id) {
                                $q->where('id', '!=', $record->id);
                            }
                            $maxHmAkhir = $q->max('hm_akhir');

                            if ($maxHmAkhir !== null && $newAwal < (float) $maxHmAkhir) {
                                $fail(sprintf(
                                    'HM Awal (%s) < HM Akhir log sebelumnya (%s). Meter jam mesin selalu naik — periksa apakah HM meter di-reset atau log sebelumnya salah.',
                                    number_format($newAwal, 1, ',', '.'),
                                    number_format((float) $maxHmAkhir, 1, ',', '.'),
                                ));
                            }
                        };
                    })
                    ->columnSpan(1),

                TextInput::make('hm_akhir')
                    ->label('HM Akhir (Sore)')
                    ->required()
                    ->numeric()
                    ->step(0.1)
                    ->placeholder('contoh: 4872.0')
                    ->live(onBlur: true)
                    // BUG-07: hard validation — HM akhir HARUS > HM awal.
                    // Pakai closure rule (bukan 'gt:hm_awal' string rule) karena
                    // Filament live validation cuma slice field yang berubah
                    // ke validator — sehingga referensi ke field lain via string
                    // rule tidak ke-resolve. Closure rule via Get() baca dari
                    // form state langsung, always accurate.
                    ->rule(static function (Get $get): \Closure {
                        return static function (string $attribute, $value, \Closure $fail) use ($get): void {
                            if ($value === null || $value === '') {
                                return; // biarkan `required` yang handle
                            }
                            $awal = (float) ($get('hm_awal') ?? 0);
                            $akhir = (float) $value;
                            if ($akhir <= $awal) {
                                $fail('HM akhir harus lebih besar dari HM awal.');
                            }
                        };
                    })
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        $awal = (float) ($get('hm_awal') ?? 0);
                        $akhir = (float) ($state ?? 0);
                        if ($akhir > $awal) {
                            $set('jam_kerja', round($akhir - $awal, 2));
                        }
                    })
                    ->columnSpan(1),

                TextInput::make('jam_kerja')
                    ->label('Jam Kerja (auto)')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->readonly()
                    ->dehydrated()
                    ->columnSpan(1),

                Placeholder::make('hm_validation')
                    ->label('')
                    ->content(function (Get $get): HtmlString {
                        $awal = (float) ($get('hm_awal') ?? 0);
                        $akhir = (float) ($get('hm_akhir') ?? 0);
                        if ($awal > 0 && $akhir > 0 && $akhir <= $awal) {
                            return new HtmlString('<div style="color: var(--danger-600); font-weight: 600;">⚠️ HM akhir harus lebih besar dari HM awal!</div>');
                        }
                        if ($awal > 0 && $akhir > $awal) {
                            return new HtmlString('<div style="color: var(--success-600); font-weight: 600;">✓ ' . round($akhir - $awal, 2) . ' jam kerja</div>');
                        }
                        return new HtmlString('');
                    })
                    ->columnSpan(1),

                TextInput::make('solar_liter')
                    ->label('Solar Actual (Liter)')
                    ->numeric()
                    ->step(0.1)
                    ->suffix('L')
                    ->live(onBlur: true)
                    ->helperText('Isi bila mau catat actual pemakaian. Bila override_biaya=OFF, nilai ini hanya sebagai catatan (tidak dipakai kalkulasi jurnal).')
                    ->columnSpan(1),

                TextInput::make('voucher_solar')
                    ->label('No. Voucher Solar')
                    ->maxLength(50)
                    ->placeholder('VOC-001')
                    ->columnSpan(1),

                Toggle::make('override_biaya')
                    ->label('Override biaya operasional')
                    ->helperText('Aktifkan bila biaya hari ini berbeda dari standar kontrak.')
                    ->default(false)
                    ->live()
                    ->columnSpanFull(),

                TextInput::make('uang_makan_operator')
                    ->label('Uang Makan (override)')
                    ->rupiah()
                    ->visible(fn (Get $get): bool => (bool) $get('override_biaya'))
                    ->live(onBlur: true)
                    ->columnSpan(1),

                TextInput::make('premi_operator')
                    ->label('Premi (override)')
                    ->rupiah()
                    ->visible(fn (Get $get): bool => (bool) $get('override_biaya'))
                    ->live(onBlur: true)
                    ->columnSpan(1),

                Placeholder::make('cost_preview')
                    ->label('Preview Biaya Operasional')
                    ->content(function ($livewire, Get $get): HtmlString {
                        $contract = $livewire->getOwnerRecord();
                        if (! $contract) {
                            return new HtmlString('<div style="opacity:0.6;">Kontrak tidak ditemukan.</div>');
                        }

                        if (! $contract->includesBbm() && ! $contract->includesOperator()) {
                            return new HtmlString('<div style="opacity:0.6;">Kontrak tipe <strong>Alat Saja</strong> — tidak ada biaya operasional dari PT.</div>');
                        }

                        $service = app(OperationalCostService::class);
                        $company = Filament::getTenant();
                        $hargaBbm = $service->resolveHargaBbm($contract->harga_bbm_per_liter, $company);

                        $cost = $service->calculateRentalCost([
                            'jam_kerja'              => $get('jam_kerja'),
                            'override_biaya'         => (bool) $get('override_biaya'),
                            'include_bbm'            => $contract->includesBbm(),
                            'include_operator'       => $contract->includesOperator(),
                            'bbm_liter_per_jam'      => $contract->bbm_liter_per_jam,
                            'harga_bbm_per_liter'    => $hargaBbm,
                            'gaji_operator_per_hari' => $contract->gaji_operator_per_hari,
                            'uang_makan_per_hari'    => $contract->uang_makan_per_hari,
                            'premi_per_jam'          => $contract->premi_per_jam,
                            'override_solar_liter'   => $get('solar_liter'),
                            'override_uang_makan'    => $get('uang_makan_operator'),
                            'override_premi'         => $get('premi_operator'),
                        ]);

                        $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

                        return new HtmlString(
                            '<div style="font-size:13px;line-height:1.7;padding:8px 12px;background:rgba(127,127,127,0.06);border-radius:6px;">'
                            . '<div>BBM: <strong>' . $fmt($cost['bbm']) . '</strong></div>'
                            . '<div>Gaji operator: <strong>' . $fmt($cost['gaji']) . '</strong></div>'
                            . '<div>Uang makan: <strong>' . $fmt($cost['makan']) . '</strong></div>'
                            . '<div>Premi: <strong>' . $fmt($cost['premi']) . '</strong></div>'
                            . '<div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(127,127,127,0.25);">Total: <strong>' . $fmt($cost['total']) . '</strong></div>'
                            . '</div>'
                        );
                    })
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
            ->recordTitleAttribute('id')
            ->defaultSort('log_date', 'desc')
            ->columns([
                TextColumn::make('log_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('operator.name')
                    ->label('Operator')
                    ->placeholder('—')
                    ->limit(25),

                TextColumn::make('hm_awal')
                    ->label('HM Awal')
                    ->numeric(decimalPlaces: 1)
                    ->alignEnd(),

                TextColumn::make('hm_akhir')
                    ->label('HM Akhir')
                    ->numeric(decimalPlaces: 1)
                    ->alignEnd(),

                TextColumn::make('jam_kerja')
                    ->label('Jam')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('solar_liter')
                    ->label('Solar (L)')
                    ->numeric(decimalPlaces: 1)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->placeholder('Belum ditagih')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Input Jam Kerja')
                    ->mutateDataUsing(function (array $data, RelationManager $livewire): array {
                        $data['created_by'] = auth()->id();
                        $assetId = $livewire->getOwnerRecord()->asset_id;
                        // Inherit asset_id dari parent contract — kolom NOT NULL,
                        // form tidak minta user pilih (karena kontrak sudah kunci alat).
                        $data['asset_id'] = $assetId;
                        // Re-calc jam_kerja saat save (sebagai backup)
                        if (isset($data['hm_awal'], $data['hm_akhir'])) {
                            // BUG-07: guard kedua di server-side. Kalau client-side rule
                            // di-bypass (mis. API direct atau JS di-nonaktifkan), ini
                            // masih menahan jam_kerja negatif masuk DB.
                            $jamKerja = round((float) $data['hm_akhir'] - (float) $data['hm_awal'], 2);
                            if ($jamKerja <= 0) {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'hm_akhir' => 'HM akhir harus lebih besar dari HM awal. Jam kerja tidak boleh nol atau negatif.',
                                ]);
                            }
                            $data['jam_kerja'] = $jamKerja;
                        }
                        // BUG-HM-OVERLAP: server-side guard. Cek HM Awal ≥ HM Akhir
                        // tertinggi dari log aset ini yang tanggalnya ≤ log baru.
                        // Mencegah double-billing jam yang sudah ke-count log sebelumnya.
                        if (isset($data['hm_awal'], $data['log_date']) && $assetId) {
                            $maxHmAkhir = RentalLog::withoutGlobalScopes()
                                ->where('asset_id', $assetId)
                                ->where('log_date', '<=', $data['log_date'])
                                ->max('hm_akhir');
                            if ($maxHmAkhir !== null && (float) $data['hm_awal'] < (float) $maxHmAkhir) {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'hm_awal' => sprintf(
                                        'HM Awal (%s) < HM Akhir log sebelumnya (%s). Meter jam mesin selalu naik — periksa apakah HM di-reset atau log sebelumnya salah.',
                                        number_format((float) $data['hm_awal'], 1, ',', '.'),
                                        number_format((float) $maxHmAkhir, 1, ',', '.'),
                                    ),
                                ]);
                            }
                        }
                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('lihat_bukti')->liatBukti(),

                EditAction::make()
                    ->visible(fn ($record): bool => $record->invoice_id === null)
                    ->mutateDataUsing(function (array $data, $record): array {
                        if (isset($data['hm_awal'], $data['hm_akhir'])) {
                            // BUG-07: guard kedua di server-side. Kalau client-side rule
                            // di-bypass (mis. API direct atau JS di-nonaktifkan), ini
                            // masih menahan jam_kerja negatif masuk DB.
                            $jamKerja = round((float) $data['hm_akhir'] - (float) $data['hm_awal'], 2);
                            if ($jamKerja <= 0) {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'hm_akhir' => 'HM akhir harus lebih besar dari HM awal. Jam kerja tidak boleh nol atau negatif.',
                                ]);
                            }
                            $data['jam_kerja'] = $jamKerja;
                        }
                        // BUG-HM-OVERLAP: server-side guard untuk edit — exclude
                        // record ini sendiri dari cek supaya tidak "menyaingi" diri.
                        if (isset($data['hm_awal'], $data['log_date']) && $record?->asset_id) {
                            $maxHmAkhir = RentalLog::withoutGlobalScopes()
                                ->where('asset_id', $record->asset_id)
                                ->where('log_date', '<=', $data['log_date'])
                                ->where('id', '!=', $record->id)
                                ->max('hm_akhir');
                            if ($maxHmAkhir !== null && (float) $data['hm_awal'] < (float) $maxHmAkhir) {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'hm_awal' => sprintf(
                                        'HM Awal (%s) < HM Akhir log lain sebelumnya (%s). Meter jam mesin selalu naik.',
                                        number_format((float) $data['hm_awal'], 1, ',', '.'),
                                        number_format((float) $maxHmAkhir, 1, ',', '.'),
                                    ),
                                ]);
                            }
                        }
                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn ($record): bool => $record->invoice_id === null),
            ]);
    }
}
