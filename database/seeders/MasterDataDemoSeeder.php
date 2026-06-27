<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Seeder DEMO data — hanya untuk PT Maju Terus (slug: maju-terus).
 *
 * BUKAN bagian dari CompanyTemplateService.
 * Tenant baru yang register via UI TIDAK akan dapat data ini.
 *
 * Jalankan via: php artisan db:seed --class=MasterDataDemoSeeder
 * Atau lewat DatabaseSeeder utama.
 */
class MasterDataDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('slug', 'maju-terus')->first();

        if (! $company) {
            $this->command->warn('Company "maju-terus" belum ada. Jalankan CompanySeeder dulu.');
            return;
        }

        $this->command->info('Seeding demo master data untuk PT Maju Terus...');

        $this->seedClients($company);
        $this->seedVendors($company);
        $this->seedEmployees($company);
        $this->seedAssets($company);

        $this->command->info('✅ Demo master data berhasil di-seed.');
    }

    private function seedClients(Company $company): void
    {
        $clients = [
            ['code' => 'CLT-001', 'name' => 'PT Wijaya Konstruksi',     'contact_person' => 'Bp. Wijaya',  'phone' => '021-5500111',  'email' => 'finance@wijaya-konstruksi.co.id',  'npwp' => '01.234.567.8-901.000', 'address' => 'Jl. Jenderal Sudirman No. 21, Jakarta'],
            ['code' => 'CLT-002', 'name' => 'PT Graha Sentosa Land',    'contact_person' => 'Ibu Sentosa', 'phone' => '021-5500222',  'email' => 'ap@grahasentosa.co.id',             'npwp' => '02.345.678.9-012.000', 'address' => 'Jl. Gatot Subroto No. 88, Jakarta'],
            ['code' => 'CLT-003', 'name' => 'CV Karya Tanah Makmur',    'contact_person' => 'Bp. Karya',   'phone' => '0274-555333',  'email' => 'karya.tanah@gmail.com',             'npwp' => null,                   'address' => 'Jl. Magelang KM 7, Yogyakarta'],
            ['code' => 'CLT-004', 'name' => 'Dinas PUPR Kabupaten',     'contact_person' => 'Bp. Kepala',  'phone' => '0271-666444',  'email' => 'pupr.dinas@gov.id',                 'npwp' => '00.000.001.0-001.000', 'address' => 'Kompleks Pemda, Solo'],
            ['code' => 'CLT-005', 'name' => 'PT Delta Agro Persada',    'contact_person' => 'Ibu Delta',   'phone' => '0511-555555',  'email' => 'procurement@deltaagro.id',          'npwp' => '03.456.789.0-123.000', 'address' => 'Jl. Trans Kalimantan KM 12, Banjarmasin'],
        ];

        foreach ($clients as $row) {
            Client::updateOrCreate(
                ['company_id' => $company->id, 'code' => $row['code']],
                $row + ['company_id' => $company->id, 'is_active' => true],
            );
        }

        $this->command->info('   → 5 clients di-seed');
    }

    private function seedVendors(Company $company): void
    {
        $vendors = [
            ['code' => 'VND-001', 'name' => 'SPBU Mitra Jaya',          'type' => 'bbm',       'contact_person' => 'Bp. Mitra',  'phone' => '021-7000111', 'email' => 'mitra@spbujaya.id'],
            ['code' => 'VND-002', 'name' => 'Toko Sumber Teknik',       'type' => 'sparepart', 'contact_person' => 'Bp. Sumber', 'phone' => '021-7000222', 'email' => 'order@sumberteknik.co.id'],
            ['code' => 'VND-003', 'name' => 'PT Kuari Batu Sentosa',    'type' => 'kuari',     'contact_person' => 'Ibu Kuari',  'phone' => '0274-700333', 'email' => 'sales@kuaribatu.co.id'],
            ['code' => 'VND-004', 'name' => 'CV Mandiri Leasing',       'type' => 'leasing',   'contact_person' => 'Bp. Leasing','phone' => '021-7000444', 'email' => 'crm@mandirileasing.id'],
            ['code' => 'VND-005', 'name' => 'PT Sinar Jasa Workshop',   'type' => 'jasa',      'contact_person' => 'Bp. Sinar',  'phone' => '021-7000555', 'email' => 'service@sinarjasa.co.id'],
        ];

        foreach ($vendors as $row) {
            Vendor::updateOrCreate(
                ['company_id' => $company->id, 'code' => $row['code']],
                $row + ['company_id' => $company->id, 'is_active' => true],
            );
        }

        $this->command->info('   → 5 vendors di-seed');
    }

    private function seedAssets(Company $company): void
    {
        // Akun aset tetap untuk armada (112100)
        $aktivaArmada = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '112100')
            ->first();

        // Kalau parent sudah HEADER, pakai postable fallback
        $defaultAccountId = $aktivaArmada
            ? (Account::findPostableByCode('112100', $company->id)?->id)
            : null;

        $assets = [
            ['asset_code' => 'DT-01',   'name' => 'Dump Truck Hino 500',         'type' => 'dump_truck',  'plate_number' => 'B 1234 XX', 'purchase_price' => 450000000, 'useful_life_months' => 60, 'salvage_value' => 50000000, 'purchase_date' => '2023-01-15'],
            ['asset_code' => 'DT-02',   'name' => 'Dump Truck Hino 500',         'type' => 'dump_truck',  'plate_number' => 'B 1235 XX', 'purchase_price' => 450000000, 'useful_life_months' => 60, 'salvage_value' => 50000000, 'purchase_date' => '2023-01-20'],
            ['asset_code' => 'DT-03',   'name' => 'Dump Truck Fuso FE74',        'type' => 'dump_truck',  'plate_number' => 'B 1236 XX', 'purchase_price' => 380000000, 'useful_life_months' => 60, 'salvage_value' => 40000000, 'purchase_date' => '2023-06-10'],
            ['asset_code' => 'DT-04',   'name' => 'Dump Truck Fuso FE74',        'type' => 'dump_truck',  'plate_number' => 'B 1237 XX', 'purchase_price' => 380000000, 'useful_life_months' => 60, 'salvage_value' => 40000000, 'purchase_date' => '2023-06-15'],
            ['asset_code' => 'EXCA-01', 'name' => 'Excavator Komatsu PC200',    'type' => 'excavator',   'plate_number' => null,        'purchase_price' => 1800000000, 'useful_life_months' => 84, 'salvage_value' => 200000000, 'purchase_date' => '2022-03-01'],
            ['asset_code' => 'BLD-01',  'name' => 'Bulldozer Komatsu D65',      'type' => 'bulldozer',   'plate_number' => null,        'purchase_price' => 2400000000, 'useful_life_months' => 96, 'salvage_value' => 300000000, 'purchase_date' => '2022-08-15'],
        ];

        foreach ($assets as $row) {
            Asset::updateOrCreate(
                ['company_id' => $company->id, 'asset_code' => $row['asset_code']],
                $row + [
                    'company_id'  => $company->id,
                    'account_id'  => $defaultAccountId,
                    'status'      => 'aktif',
                ],
            );
        }

        $this->command->info('   → 6 assets di-seed (4 Dump Truck, 1 Excavator, 1 Bulldozer)');
    }

    private function seedEmployees(Company $company): void
    {
        $employees = [
            ['employee_id' => 'EMP-001', 'name' => 'Budi Santoso',  'position' => 'driver',  'phone' => '0812-1111-1111', 'join_date' => '2022-05-01'],
            ['employee_id' => 'EMP-002', 'name' => 'Agus Rahmadi',  'position' => 'driver',  'phone' => '0812-2222-2222', 'join_date' => '2022-08-15'],
            ['employee_id' => 'EMP-003', 'name' => 'Deni Wijaya',   'position' => 'driver',  'phone' => '0812-3333-3333', 'join_date' => '2023-01-10'],
            ['employee_id' => 'EMP-004', 'name' => 'Hendra Kurnia', 'position' => 'operator','phone' => '0813-4444-4444', 'join_date' => '2022-03-01'],
            ['employee_id' => 'EMP-005', 'name' => 'Rudi Mekanik',  'position' => 'mekanik', 'phone' => '0813-5555-5555', 'join_date' => '2021-11-15'],
        ];

        foreach ($employees as $row) {
            Employee::updateOrCreate(
                ['company_id' => $company->id, 'employee_id' => $row['employee_id']],
                $row + ['company_id' => $company->id, 'is_active' => true],
            );
        }

        $this->command->info('   → 5 employees di-seed (3 driver, 1 operator, 1 mekanik)');
    }
}
