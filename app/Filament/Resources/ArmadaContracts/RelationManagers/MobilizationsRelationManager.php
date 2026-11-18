<?php

namespace App\Filament\Resources\ArmadaContracts\RelationManagers;

use App\Models\ArmadaMobilization;
use App\Models\Asset;
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

/**
 * Mobilisasi Alat Berat: mobilisasi / demobilisasi alat berat dengan truk self loader.
 * Ditagih bersama pemakaian kontrak lewat tombol Tagih (satu invoice).
 * Baris yang sudah masuk invoice terkunci — void invoice untuk mengubah.
 */
class MobilizationsRelationManager extends RelationManager
{
    protected static string $relationship = 'mobilizations';

    protected static ?string $title = 'Mobilisasi Alat Berat';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('tanggal')
                    ->label('Tanggal Angkut')
                    ->required()
                    ->default(now())
                    ->native(false),

                Select::make('jenis')
                    ->label('Jenis')
                    ->native(false)
                    ->required()
                    ->default('mobilisasi')
                    ->options(ArmadaMobilization::JENIS),

                TextInput::make('alat_diangkut')
                    ->label('Alat yang Diangkut')
                    ->required()
                    ->maxLength(150)
                    ->placeholder('contoh: Excavator PC 75'),

                Select::make('asset_id')
                    ->label('Truk Pengangkut (self loader)')
                    ->native(false)
                    ->searchable()
                    ->placeholder('Opsional')
                    ->options(function (): array {
                        $tenant = Filament::getTenant();

                        return Asset::query()
                            ->when($tenant, fn ($q) => $q->where('company_id', $tenant->getKey()))
                            ->whereIn('type', ['dump_truck', 'kendaraan_operasional', 'lainnya'])
                            ->orderBy('asset_code')
                            ->get()
                            ->mapWithKeys(fn ($a) => [$a->id => "[{$a->asset_code}] {$a->name}"])
                            ->all();
                    })
                    ->helperText('Diisi supaya pendapatan mobilisasi masuk Laba Rugi per Unit truk tersebut.'),

                TextInput::make('amount')
                    ->label('Biaya Mobilisasi (Rp)')
                    ->required()
                    ->rupiah()
                    ->placeholder('contoh: 2500000'),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'invoice']))
            ->defaultSort('tanggal', 'desc')
            ->emptyStateHeading('Belum ada mobilisasi alat berat')
            ->emptyStateDescription('Catat biaya mobilisasi/demobilisasi alat berat dengan truk self loader. Akan ikut ditagih saat klik Tagih di daftar kontrak.')
            ->columns([
                TextColumn::make('tanggal')->label('Tanggal')->date('d M Y')->sortable(),

                TextColumn::make('jenis')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'demobilisasi' ? 'Demobilisasi' : 'Mobilisasi')
                    ->color(fn ($state) => $state === 'demobilisasi' ? 'gray' : 'info'),

                TextColumn::make('alat_diangkut')->label('Alat Diangkut')->wrap(),

                TextColumn::make('asset.asset_code')->label('Truk')->badge()->placeholder('—'),

                TextColumn::make('amount')->label('Ongkos')->money('IDR', 0)->alignEnd()->weight('bold'),

                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->placeholder('Belum ditagih')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Catat Mobilisasi')
                    ->visible(fn (): bool => $this->getOwnerRecord()->isAktif())
                    ->mutateDataUsing(fn (array $data): array => array_merge($data, [
                        'company_id' => $this->getOwnerRecord()->company_id,
                        'created_by' => auth()->id(),
                    ])),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (ArmadaMobilization $record): bool => ! $record->isBilled()),
                DeleteAction::make()->visible(fn (ArmadaMobilization $record): bool => ! $record->isBilled()),
            ]);
    }
}
