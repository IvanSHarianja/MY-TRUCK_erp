<?php

namespace Tests\Feature\Accounting;

use App\Models\ArmadaContract;
use App\Models\AssetMaintenanceLog;
use App\Models\Asset;
use App\Models\Material;
use App\Models\Project;
use App\Models\RentalContract;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression test — bug rupiah 100× lipat.
 *
 * Root cause historis: kolom Rp di-cast `decimal:2` → Eloquent return string
 * "250000.00" saat load → Filament mask `$money($input,',','.',0)` salah
 * interpret titik desimal sebagai thousand separator → tampil 10× / 100× lipat
 * saat edit. Save berulang → data korup permanen di DB.
 *
 * Test ini memverifikasi cast `integer` di semua model uang: create → save
 * → load kembali → nilai TIDAK berubah (tidak dikali 10 atau 100).
 *
 * Bila test ini merah, kemungkinan cast di model diubah kembali ke decimal:2
 * secara tidak sengaja — jangan revert kecuali paham konsekuensinya
 * (dan pastikan form tidak lagi pakai ->rupiah()).
 */
class RupiahCastRoundTripTest extends TestCase
{
    public function test_asset_purchase_price_dan_salvage_tetap_integer(): void
    {
        $company = $this->createTenant();

        $asset = Asset::create([
            'company_id'         => $company->id,
            'asset_code'         => 'AST-' . strtoupper(Str::random(4)),
            'name'               => 'Round-trip test',
            'type'               => 'excavator',
            'purchase_date'      => '2024-01-15',
            'purchase_price'     => 20_000_000,
            'salvage_value'      => 2_000_000,
            'useful_life_months' => 60,
            'status'             => 'aktif',
        ]);

        $reload = Asset::withoutGlobalScopes()->find($asset->id);
        $this->assertSame(20_000_000, $reload->purchase_price);
        $this->assertSame(2_000_000, $reload->salvage_value);
    }

    public function test_armada_contract_field_rp_tetap_integer(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $client  = $this->createClient($company);

        $contract = ArmadaContract::create([
            'company_id'         => $company->id,
            'contract_number'    => 'AC-' . strtoupper(Str::random(4)),
            'client_id'          => $client->id,
            'tipe_kontrak'       => 'all_in',
            'include_bbm'        => true,
            'include_operator'   => true,
            'route_description'  => 'Test route',
            'tarif_per_rit'       => 250_000,
            'bbm_liter_per_rit'   => 15,   // liter — decimal
            'harga_bbm_per_liter' => 6_500,
            'gaji_supir_per_hari' => 200_000,
            'uang_makan_per_hari' => 50_000,
            'uang_jalan_per_rit'  => 25_000,
            'premi_per_rit'       => 10_000,
            'billed_rit'          => 0,
            'status'              => 'aktif',
            'started_at'          => '2026-01-01',
            'created_by'          => $user->id,
        ]);

        $reload = ArmadaContract::withoutGlobalScopes()->find($contract->id);

        $this->assertSame(250_000, $reload->tarif_per_rit);
        $this->assertSame(6_500, $reload->harga_bbm_per_liter);
        $this->assertSame(200_000, $reload->gaji_supir_per_hari);
        $this->assertSame(50_000, $reload->uang_makan_per_hari);
        $this->assertSame(25_000, $reload->uang_jalan_per_rit);
        $this->assertSame(10_000, $reload->premi_per_rit);

        // bbm_liter_per_rit tetap decimal — masih string atau float (bukan int)
        $this->assertIsNotInt($reload->bbm_liter_per_rit);
    }

    public function test_rental_contract_field_rp_tetap_integer(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $client  = $this->createClient($company);
        $asset   = Asset::create([
            'company_id'         => $company->id,
            'asset_code'         => 'AST-' . strtoupper(Str::random(4)),
            'name'               => 'Test',
            'type'               => 'excavator',
            'purchase_date'      => '2024-01-15',
            'purchase_price'     => 500_000_000,
            'salvage_value'      => 50_000_000,
            'useful_life_months' => 60,
            'status'             => 'aktif',
        ]);

        $contract = RentalContract::create([
            'company_id'             => $company->id,
            'contract_number'        => 'RC-' . strtoupper(Str::random(4)),
            'client_id'              => $client->id,
            'asset_id'               => $asset->id,
            'tipe_rental'            => 'all_in',
            'include_bbm'            => true,
            'include_operator'       => true,
            'tarif_per_jam'          => 350_000,
            'bbm_liter_per_jam'      => 12.5,
            'harga_bbm_per_liter'    => 6_500,
            'gaji_operator_per_hari' => 250_000,
            'uang_makan_per_hari'    => 50_000,
            'premi_per_jam'          => 15_000,
            'billed_jam'             => 0,
            'status'                 => 'aktif',
            'started_at'             => '2026-01-01',
            'created_by'             => $user->id,
        ]);

        $reload = RentalContract::withoutGlobalScopes()->find($contract->id);

        $this->assertSame(350_000, $reload->tarif_per_jam);
        $this->assertSame(6_500, $reload->harga_bbm_per_liter);
        $this->assertSame(250_000, $reload->gaji_operator_per_hari);
        $this->assertSame(50_000, $reload->uang_makan_per_hari);
        $this->assertSame(15_000, $reload->premi_per_jam);
    }

    public function test_material_harga_tetap_integer(): void
    {
        $company = $this->createTenant();

        $material = Material::create([
            'company_id'       => $company->id,
            'code'             => 'MAT-' . strtoupper(Str::random(4)),
            'name'             => 'Pasir Uji',
            'harga_per_satuan' => 150_000,
            'harga_pokok'      => 100_000,
            'satuan'           => 'm3',
            'is_active'        => true,
        ]);

        $reload = Material::withoutGlobalScopes()->find($material->id);
        $this->assertSame(150_000, $reload->harga_per_satuan);
        $this->assertSame(100_000, $reload->harga_pokok);
    }

    public function test_project_nilai_kontrak_tetap_integer(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $client  = $this->createClient($company);

        $project = Project::create([
            'company_id'      => $company->id,
            'project_number'  => 'PRJ-' . strtoupper(Str::random(4)),
            'name'            => 'Test project',
            'jenis_pekerjaan' => 'Pengurugan',
            'client_id'       => $client->id,
            'nilai_kontrak'   => 1_500_000_000,
            'dp_diterima'     => 300_000_000,
            'progress_pct'    => 25.5,
            'tertagih_pct'    => 20,
            'status'          => 'berjalan',
            'started_at'      => '2026-01-01',
            'target_end_date' => '2026-12-31',
            'created_by'      => $user->id,
        ]);

        $reload = Project::withoutGlobalScopes()->find($project->id);

        $this->assertSame(1_500_000_000, $reload->nilai_kontrak);
        $this->assertSame(300_000_000, $reload->dp_diterima);
        // Progress % tetap decimal
        $this->assertIsNotInt($reload->progress_pct);
    }

    public function test_maintenance_log_cost_tetap_integer(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);

        $asset = Asset::create([
            'company_id'         => $company->id,
            'asset_code'         => 'AST-' . strtoupper(Str::random(4)),
            'name'               => 'Test',
            'type'               => 'excavator',
            'purchase_date'      => '2024-01-15',
            'purchase_price'     => 100_000_000,
            'salvage_value'      => 10_000_000,
            'useful_life_months' => 60,
            'status'             => 'aktif',
        ]);

        $log = AssetMaintenanceLog::create([
            'company_id'       => $company->id,
            'asset_id'         => $asset->id,
            'maintenance_date' => '2026-08-01',
            'type'             => 'service_rutin',
            'cost'             => 750_000,
            'description'      => 'Test',
            'created_by'       => $user->id,
        ]);

        $reload = AssetMaintenanceLog::withoutGlobalScopes()->find($log->id);
        $this->assertSame(750_000, $reload->cost);
    }

    public function test_company_harga_solar_default_tetap_integer(): void
    {
        $company = $this->createTenant();
        $company->update(['harga_solar_default' => 6_800]);

        $reload = \App\Models\Company::withoutGlobalScopes()->find($company->id);
        $this->assertSame(6_800, $reload->harga_solar_default);
    }
}
