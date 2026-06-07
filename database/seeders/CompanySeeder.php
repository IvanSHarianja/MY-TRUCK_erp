<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyTemplateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
    }
}
