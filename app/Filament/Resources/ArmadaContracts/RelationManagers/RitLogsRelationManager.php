<?php

namespace App\Filament\Resources\ArmadaContracts\RelationManagers;

use App\Models\Asset;
use App\Models\Employee;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->step(1),

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
