<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder utama — orchestrate semua seeder.
 *
 * Urutan eksekusi:
 *  1. UserSeeder         → admin@mytruck.test
 *  2. CompanySeeder      → PT Maju Terus + master akuntansi default (53 akun + 5 BU + 7 material)
 *  3. MasterDataDemoSeeder → 5 clients + 5 vendors + 5 employees + 6 assets (HANYA untuk PT Maju Terus)
 *
 * CATATAN:
 * - Tenant baru yang register via UI hanya dapat hasil CompanyTemplateService (master akuntansi).
 *   Tidak dapat data demo (client/vendor/employee/asset).
 * - Demo data hanya untuk PT Maju Terus, untuk keperluan testing & demo ke calon klien.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CompanySeeder::class,
            MasterDataDemoSeeder::class,
        ]);
    }
}
