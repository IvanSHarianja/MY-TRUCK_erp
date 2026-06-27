<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyTemplateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * CompanySeeder — bikin company demo "PT Maju Terus" + assign user demo + seed template default.
 *
 * Template default via CompanyTemplateService:
 *  - 53 akun COA (termasuk 221170 Uang Muka Proyek, 551600 Beban Subkontraktor)
 *  - 5 BusinessUnit: RENT, ARMD, MATL, BONG, UMUM
 *  - 7 Material default (Tanah Urug, Sirtu, Pasir, Batu, dll)
 *
 * Untuk demo master data (clients, vendors, assets, employees), lihat MasterDataDemoSeeder.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@mytruck.test'],
            [
                'name'     => 'Admin Demo',
                'password' => Hash::make('password'),
            ],
        );

        $company = Company::firstOrCreate(
            ['slug' => 'maju-terus'],
            [
                'name'         => 'PT Maju Terus',
                'owner_name'   => 'Bapak Demo',
                'fiscal_year'  => date('Y'),
                'fiscal_start' => now()->startOfYear()->toDateString(),
                'fiscal_end'   => now()->endOfYear()->toDateString(),
                'is_active'    => true,
            ],
        );

        $company->users()->syncWithoutDetaching([
            $user->id => ['role' => 'owner', 'is_active' => true],
        ]);

        app(CompanyTemplateService::class)->seedDefaults($company);

        $this->command?->info('✅ Company "PT Maju Terus" siap (admin: admin@mytruck.test / password)');
    }
}
