<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Enums\MaintenanceType;
use App\Models\Vendor;
use App\Services\Accounting\MaintenanceService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

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
                    ->label('Biaya Total')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->default(0)
                    ->helperText('Isi 0 untuk service gratis / garansi — jurnal tidak akan dibuat.'),

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
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
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
                    ->label('Biaya')
                    ->money('IDR')
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
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
