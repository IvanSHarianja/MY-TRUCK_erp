<?php

namespace App\Filament\Concerns;

use App\Enums\Permission;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Tombol "Import Excel" di halaman daftar Klien / Vendor → halaman import
 * dengan pratinjau (ImportClients / ImportVendors).
 *
 * @mixin \Filament\Resources\Pages\ListRecords
 */
trait ImportsPartiesFromExcel
{
    protected function importExcelAction(): Action
    {
        return Action::make('importExcel')
            ->label('Import Excel')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->canCurrent(Permission::MasterDataManage) ?? false)
            ->url(fn (): string => static::getResource()::getUrl('import'));
    }
}
