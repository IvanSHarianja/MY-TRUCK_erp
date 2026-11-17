<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Widgets\AccountRoleReadinessNotice;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Peringatan peran akun yang belum lengkap (terutama setelah import COA
     * dengan kode non-standar) — dulu subjudul satu paragraf panjang, sekarang
     * kotak ringkas yang rinciannya bisa dibuka.
     */
    protected function getHeaderWidgets(): array
    {
        return [AccountRoleReadinessNotice::class];
    }
}
