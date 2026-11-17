<?php

namespace App\Filament\Resources\Accounts\Widgets;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Services\CoaImportService;
use App\Support\AccountRoleReadiness;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Kotak peringatan ringkas di atas Daftar Akun: fitur yang belum bisa
 * menjurnal otomatis karena Peran akun belum ada, plus akun yang Perannya
 * hasil tebakan import.
 *
 * Menggantikan subjudul satu paragraf panjang — ringkasan satu baris, rincian
 * per fitur dilipat (<details>), dan tiap temuan punya jalan pintas ke filter
 * tabel supaya user langsung bisa bertindak.
 */
class AccountRoleReadinessNotice extends Widget
{
    protected string $view = 'filament.resources.accounts.widgets.account-role-readiness-notice';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Filament::getTenant() !== null;
    }

    protected function getViewData(): array
    {
        $companyId = Filament::getTenant()->getKey();

        $missing = AccountRoleReadiness::missing($companyId);
        // Akun tanpa Peran TIDAK dihitung di sini: banyak akun memang tidak
        // butuh Peran (mis. Beban Listrik) — angkanya hanya akan membuat panik.
        $guessed = Account::query()->where('notes', 'like', CoaImportService::GUESSED_ROLE_PREFIX . '%')->count();

        return [
            'missing'    => $missing,
            'guessed'    => $guessed,
            'guessedUrl' => AccountResource::getUrl('index') . '?'
                . http_build_query(['filters' => ['perhatian' => ['value' => 'tebakan']]]),
        ];
    }
}
