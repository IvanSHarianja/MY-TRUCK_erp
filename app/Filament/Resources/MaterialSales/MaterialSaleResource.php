<?php

namespace App\Filament\Resources\MaterialSales;

use App\Filament\Resources\MaterialSales\Pages\CreateMaterialSale;
use App\Filament\Resources\MaterialSales\Pages\EditMaterialSale;
use App\Filament\Resources\MaterialSales\Pages\ListMaterialSales;
use App\Filament\Resources\MaterialSales\Schemas\MaterialSaleForm;
use App\Filament\Resources\MaterialSales\Tables\MaterialSalesTable;
use App\Models\MaterialSale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MaterialSaleResource extends Resource
{
    protected static ?string $model = MaterialSale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Penjualan Material';

    protected static ?string $modelLabel = 'Penjualan Material';

    protected static ?string $pluralModelLabel = 'Penjualan Material';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'sale_number';

    public static function form(Schema $schema): Schema
    {
        return MaterialSaleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaterialSalesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListMaterialSales::route('/'),
            'create' => CreateMaterialSale::route('/create'),
            'edit'   => EditMaterialSale::route('/{record}/edit'),
        ];
    }
}
