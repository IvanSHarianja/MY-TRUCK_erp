<?php

namespace App\Filament\Resources\RentalContracts\RelationManagers;

use App\Models\Employee;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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
        return $schema
            ->components([
                DatePicker::make('log_date')
                    ->label('Tanggal')
                    ->required()
                    ->default(now())
                    ->native(false)
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
                    ->columnSpan(1),

                TextInput::make('hm_akhir')
                    ->label('HM Akhir (Sore)')
                    ->required()
                    ->numeric()
                    ->step(0.1)
                    ->placeholder('contoh: 4872.0')
                    ->live(onBlur: true)
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
                    ->label('Solar (Liter)')
                    ->numeric()
                    ->step(0.1)
                    ->suffix('L')
                    ->columnSpan(1),

                TextInput::make('voucher_solar')
                    ->label('No. Voucher Solar')
                    ->maxLength(50)
                    ->placeholder('VOC-001')
                    ->columnSpan(1),

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
                    ->mutateDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();
                        // Re-calc jam_kerja saat save (sebagai backup)
                        if (isset($data['hm_awal'], $data['hm_akhir'])) {
                            $data['jam_kerja'] = round((float) $data['hm_akhir'] - (float) $data['hm_awal'], 2);
                        }
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record): bool => $record->invoice_id === null)
                    ->mutateDataUsing(function (array $data): array {
                        if (isset($data['hm_awal'], $data['hm_akhir'])) {
                            $data['jam_kerja'] = round((float) $data['hm_akhir'] - (float) $data['hm_awal'], 2);
                        }
                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn ($record): bool => $record->invoice_id === null),
            ]);
    }
}
