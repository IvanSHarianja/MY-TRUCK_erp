<?php

namespace App\Filament\Resources\ArmadaContracts;

use App\Filament\Resources\ArmadaContracts\Pages\CreateArmadaContract;
use App\Filament\Resources\ArmadaContracts\Pages\EditArmadaContract;
use App\Filament\Resources\ArmadaContracts\Pages\ListArmadaContracts;
use App\Filament\Resources\ArmadaContracts\RelationManagers\RitLogsRelationManager;
use App\Filament\Resources\ArmadaContracts\Schemas\ArmadaContractForm;
use App\Filament\Resources\ArmadaContracts\Tables\ArmadaContractsTable;
use App\Models\ArmadaContract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ArmadaContractResource extends Resource
{
    protected static ?string $model = ArmadaContract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Kontrak Armada';

    protected static ?string $modelLabel = 'Kontrak Armada';

    protected static ?string $pluralModelLabel = 'Kontrak Armada';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'contract_number';

    public static function form(Schema $schema): Schema
    {
        return ArmadaContractForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArmadaContractsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RitLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListArmadaContracts::route('/'),
            'create' => CreateArmadaContract::route('/create'),
            'edit'   => EditArmadaContract::route('/{record}/edit'),
        ];
    }
}
