<?php

namespace App\Filament\Resources\ArmadaContracts\RelationManagers;

use App\Models\Asset;
use App\Models\Employee;
use App\Services\Accounting\OperationalCostService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class RitLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'ritLogs';

    protected static ?string $title = 'Log Ritase Harian';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('log_date')
                    ->label('Tanggal')
                    ->required()
                    ->default(now())
                    ->native(false),

                Select::make('asset_id')
                    ->label('Dump Truck')
                    ->required()
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Asset::query()
                            ->whereIn('type', ['dump_truck', 'kendaraan_operasional']);
                        if ($tenant) {
                            $query->where('company_id', $tenant->getKey());
                        }
                        return $query->orderBy('asset_code')->get()
                            ->mapWithKeys(fn ($a) => [$a->id => "[{$a->asset_code}] {$a->name}"])
                            ->toArray();
                    })
                    ->searchable(),

                Select::make('driver_id')
                    ->label('Driver')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Employee::query()
                            ->where('is_active', true)
                            ->where('position', 'driver');
                        if ($tenant) {
                            $query->where('company_id', $tenant->getKey());
                        }
                        return $query->orderBy('name')->get()
                            ->mapWithKeys(fn ($e) => [$e->id => "[{$e->employee_id}] {$e->name}"])
                            ->toArray();
                    })
                    ->searchable(),

                TextInput::make('rit_count')
                    ->label('Jumlah Rit')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->step(1)
                    ->live(onBlur: true),

                TextInput::make('solar_liter')
                    ->label('Solar Actual (Liter)')
                    ->numeric()
                    ->step(0.1)
                    ->suffix('L')
                    ->live(onBlur: true)
                    ->helperText('Isi bila mau catat actual. Bila override_biaya=OFF, dianggap catatan (kalkulasi jurnal pakai estimasi dari kontrak).'),

                Toggle::make('override_biaya')
                    ->label('Override biaya operasional')
                    ->helperText('Aktifkan bila biaya hari ini berbeda dari standar kontrak.')
                    ->default(false)
                    ->live()
                    ->columnSpanFull(),

                TextInput::make('uang_jalan_supir')
                    ->label('Uang Jalan (override)')
                    ->numeric()
                    ->prefix('Rp')
                    ->visible(fn (Get $get): bool => (bool) $get('override_biaya'))
                    ->live(onBlur: true),

                TextInput::make('uang_makan_supir')
                    ->label('Uang Makan (override)')
                    ->numeric()
                    ->prefix('Rp')
                    ->visible(fn (Get $get): bool => (bool) $get('override_biaya'))
                    ->live(onBlur: true),

                TextInput::make('premi_supir')
                    ->label('Premi (override)')
                    ->numeric()
                    ->prefix('Rp')
                    ->visible(fn (Get $get): bool => (bool) $get('override_biaya'))
                    ->live(onBlur: true)
                    ->columnSpanFull(),

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

                        $cost = $service->calculateRitCost([
                            'rit_count'           => $get('rit_count'),
                            'override_biaya'      => (bool) $get('override_biaya'),
                            'include_bbm'         => $contract->includesBbm(),
                            'include_operator'    => $contract->includesOperator(),
                            'bbm_liter_per_rit'   => $contract->bbm_liter_per_rit,
                            'harga_bbm_per_liter' => $hargaBbm,
                            'gaji_supir_per_hari' => $contract->gaji_supir_per_hari,
                            'uang_makan_per_hari' => $contract->uang_makan_per_hari,
                            'uang_jalan_per_rit'  => $contract->uang_jalan_per_rit,
                            'premi_per_rit'       => $contract->premi_per_rit,
                            'override_solar_liter'=> $get('solar_liter'),
                            'override_uang_jalan' => $get('uang_jalan_supir'),
                            'override_uang_makan' => $get('uang_makan_supir'),
                            'override_premi'      => $get('premi_supir'),
                        ]);

                        $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

                        return new HtmlString(
                            '<div style="font-size:13px;line-height:1.7;padding:8px 12px;background:rgba(127,127,127,0.06);border-radius:6px;">'
                            . '<div>BBM: <strong>' . $fmt($cost['bbm']) . '</strong></div>'
                            . '<div>Gaji supir: <strong>' . $fmt($cost['gaji']) . '</strong></div>'
                            . '<div>Uang makan: <strong>' . $fmt($cost['makan']) . '</strong></div>'
                            . '<div>Uang jalan: <strong>' . $fmt($cost['uang_jalan']) . '</strong></div>'
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
            ]);
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

                TextColumn::make('asset.asset_code')
                    ->label('DT')
                    ->badge(),

                TextColumn::make('driver.name')
                    ->label('Driver')
                    ->placeholder('—')
                    ->limit(25),

                TextColumn::make('rit_count')
                    ->label('Rit')
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->placeholder('Belum ditagih')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(30)
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Input Ritase')
                    ->mutateDataUsing(fn (array $data): array => array_merge($data, [
                        'created_by' => auth()->id(),
                    ])),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record): bool => $record->invoice_id === null),
                DeleteAction::make()
                    ->visible(fn ($record): bool => $record->invoice_id === null),
            ]);
    }
}
