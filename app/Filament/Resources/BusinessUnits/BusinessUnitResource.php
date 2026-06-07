<?php

namespace App\Filament\Resources\BusinessUnits;

use App\Filament\Resources\BusinessUnits\Pages\CreateBusinessUnit;
use App\Filament\Resources\BusinessUnits\Pages\EditBusinessUnit;
use App\Filament\Resources\BusinessUnits\Pages\ListBusinessUnits;
use App\Filament\Resources\BusinessUnits\Schemas\BusinessUnitForm;
use App\Filament\Resources\BusinessUnits\Tables\BusinessUnitsTable;
use App\Models\BusinessUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BusinessUnitResource extends Resource
{
    protected static ?string $model = BusinessUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Lini Bisnis';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return BusinessUnitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BusinessUnitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBusinessUnits::route('/'),
            'create' => CreateBusinessUnit::route('/create'),
            'edit'   => EditBusinessUnit::route('/{record}/edit'),
        ];
    }
}
