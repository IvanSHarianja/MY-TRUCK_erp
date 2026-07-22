<?php

namespace App\Filament\Resources\AccountMappings;

use App\Filament\Resources\AccountMappings\Pages\CreateAccountMapping;
use App\Filament\Resources\AccountMappings\Pages\EditAccountMapping;
use App\Filament\Resources\AccountMappings\Pages\ListAccountMappings;
use App\Filament\Resources\AccountMappings\Schemas\AccountMappingForm;
use App\Filament\Resources\AccountMappings\Tables\AccountMappingsTable;
use App\Models\AccountMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AccountMappingResource extends Resource
{
    protected static ?string $model = AccountMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'transaction_type';

    /**
     * Hide dari sidebar/navigation sampai fitur ini selesai di-integrate ke
     * service layer. Sekarang form & table masih stub (kosong), belum ada
     * konsumen kode-nya. Migration + model tetap ada supaya kalau di masa
     * depan fitur ini di-develop, data tidak hilang.
     *
     * Untuk aktifkan kembali: hapus method ini atau return true.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Blokir akses via URL langsung juga — bukan cuma hide dari sidebar.
     * Kalau nanti fitur ready, ubah return false ke logic role/policy sesungguhnya.
     */
    public static function canAccess(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return AccountMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountMappingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountMappings::route('/'),
            'create' => CreateAccountMapping::route('/create'),
            'edit' => EditAccountMapping::route('/{record}/edit'),
        ];
    }
}
