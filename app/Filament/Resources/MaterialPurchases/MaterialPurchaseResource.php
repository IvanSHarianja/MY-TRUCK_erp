<?php

namespace App\Filament\Resources\MaterialPurchases;

use App\Filament\Resources\MaterialPurchases\Pages\CreateMaterialPurchase;
use App\Filament\Resources\MaterialPurchases\Pages\ListMaterialPurchases;
use App\Filament\Resources\MaterialPurchases\Schemas\MaterialPurchaseForm;
use App\Filament\Resources\MaterialPurchases\Tables\MaterialPurchasesTable;
use App\Models\MaterialPurchase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MaterialPurchaseResource extends Resource
{
    protected static ?string $model = MaterialPurchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Pembelian Material';

    protected static ?string $modelLabel = 'Pembelian Material';

    protected static ?string $pluralModelLabel = 'Pembelian Material';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'purchase_number';

    public static function form(Schema $schema): Schema
    {
        return MaterialPurchaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaterialPurchasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListMaterialPurchases::route('/'),
            'create' => CreateMaterialPurchase::route('/create'),
        ];
    }
}
