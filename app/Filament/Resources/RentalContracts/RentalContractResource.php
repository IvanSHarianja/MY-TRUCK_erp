<?php

namespace App\Filament\Resources\RentalContracts;

use App\Filament\Resources\RentalContracts\Pages\CreateRentalContract;
use App\Filament\Resources\RentalContracts\Pages\EditRentalContract;
use App\Filament\Resources\RentalContracts\Pages\ListRentalContracts;
use App\Filament\Resources\RentalContracts\RelationManagers\RentalLogsRelationManager;
use App\Filament\Resources\RentalContracts\Schemas\RentalContractForm;
use App\Filament\Resources\RentalContracts\Tables\RentalContractsTable;
use App\Models\RentalContract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RentalContractResource extends Resource
{
    protected static ?string $model = RentalContract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Rental Alat Berat';

    protected static ?string $modelLabel = 'Kontrak Rental';

    protected static ?string $pluralModelLabel = 'Rental Alat Berat';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'contract_number';

    public static function form(Schema $schema): Schema
    {
        return RentalContractForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RentalContractsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RentalLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListRentalContracts::route('/'),
            'create' => CreateRentalContract::route('/create'),
            'edit'   => EditRentalContract::route('/{record}/edit'),
        ];
    }
}
