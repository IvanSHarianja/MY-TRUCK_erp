<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
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
     * dengan kode non-standar) + jumlah peran hasil tebakan yang perlu ditinjau.
     */
    public function getSubheading(): ?string
    {
        $tenant  = \Filament\Facades\Filament::getTenant();
        $summary = \App\Support\AccountRoleReadiness::summary($tenant->getKey());
        $guessed = \App\Models\Account::query()->where('notes', 'like', 'Peran diisi otomatis%')->count();

        $parts = array_filter([
            $summary,
            $guessed > 0 ? "{$guessed} akun berperan hasil tebakan saat import — buka akunnya, periksa Peran, lalu hapus catatannya." : null,
        ]);

        return $parts ? implode(' ', $parts) : null;
    }
}
