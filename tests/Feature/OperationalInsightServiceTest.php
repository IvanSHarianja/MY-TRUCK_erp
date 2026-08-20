<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Company;
use App\Models\Material;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\OperationalInsightService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test OperationalInsightService aggregations untuk Dashboard Operasional.
 */
class OperationalInsightServiceTest extends TestCase
{
    private OperationalInsightService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OperationalInsightService::class);
    }

    private function makeAsset(Company $company, array $attrs = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'          => $company->id,
            'asset_code'          => 'AST-' . strtoupper(Str::random(4)),
            'name'                => 'Test',
            'type'                => 'excavator',
            'purchase_date'       => '2024-01-15',
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'depreciation_method' => 'straight_line',
            'useful_life_months'  => 60,
            'status'              => 'aktif',
        ], $attrs));
    }

    private function makeContract(Company $company, Asset $asset, Client $client, int $userId): RentalContract
    {
        return RentalContract::create([
            'company_id'      => $company->id,
            'contract_number' => 'RC-' . strtoupper(Str::random(4)),
            'client_id'       => $client->id,
            'asset_id'        => $asset->id,
            'tipe_rental'     => 'alat_saja',
            'include_bbm'     => false,
            'include_operator'=> false,
            'tarif_per_jam'   => 200_000,
            'billed_jam'      => 0,
            'status'          => 'aktif',
            'started_at'      => '2026-01-01',
            'created_by'      => $userId,
        ]);
    }

    // ============================================================
    // Stats — bulan ini vs bulan lalu
    // ============================================================

    public function test_stats_hitung_jam_bulan_ini_dari_rental_log(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->makeAsset($company);
        $client   = $this->createClient($company);
        $contract = $this->makeContract($company, $asset, $client, $user->id);

        // Log bulan ini
        RentalLog::create([
            'company_id'         => $company->id,
            'rental_contract_id' => $contract->id,
            'asset_id'           => $asset->id,
            'log_date'           => now()->startOfMonth()->addDays(2),
            'hm_awal'            => 100,
            'hm_akhir'           => 108,
            'jam_kerja'          => 8,
            'created_by'         => $user->id,
        ]);

        $stats = $this->service->stats($company->id);
        $this->assertSame(8.0, $stats['jam_bulan_ini']);
    }

    // ============================================================
    // Unbilled logs — potensi cash-in
    // ============================================================

    public function test_unbilled_logs_hitung_estimasi_dari_tarif_kontrak(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->makeAsset($company);
        $client   = $this->createClient($company);
        $contract = $this->makeContract($company, $asset, $client, $user->id);

        // 3 log belum ditagih (invoice_id null), total 20 jam
        for ($i = 0; $i < 3; $i++) {
            RentalLog::create([
                'company_id'         => $company->id,
                'rental_contract_id' => $contract->id,
                'asset_id'           => $asset->id,
                'log_date'           => now()->subDays(5 + $i),
                'hm_awal'            => 100 + $i * 10,
                'hm_akhir'           => 100 + $i * 10 + ($i === 0 ? 8 : 6),
                'jam_kerja'          => $i === 0 ? 8 : 6,
                'created_by'         => $user->id,
            ]);
        }

        $rows = $this->service->unbilledLogs($company->id);
        $this->assertCount(1, $rows);
        $this->assertSame('RENT', $rows[0]['contract_type']);
        $this->assertSame(20.0, $rows[0]['unbilled_qty']);
        // 20 jam × 200rb = 4jt
        $this->assertSame(4_000_000, $rows[0]['estimated_value']);

        $total = $this->service->unbilledPotentialTotal($company->id);
        $this->assertSame(4_000_000, $total);
    }

    // ============================================================
    // Utilization — sort ascending
    // ============================================================

    public function test_utilization_rate_sort_ascending_underutilized_di_atas(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // 2 aset — A intensif dipakai, B jarang
        $assetA = $this->makeAsset($company, ['asset_code' => 'A-INTENSIF']);
        $assetB = $this->makeAsset($company, ['asset_code' => 'B-JARANG']);

        $client = $this->createClient($company);
        $contractA = $this->makeContract($company, $assetA, $client, $user->id);
        $contractB = $this->makeContract($company, $assetB, $client, $user->id);

        // Aset A: 150 jam bulan ini
        RentalLog::create([
            'company_id' => $company->id, 'rental_contract_id' => $contractA->id,
            'asset_id' => $assetA->id, 'log_date' => now()->startOfMonth(),
            'hm_awal' => 0, 'hm_akhir' => 150, 'jam_kerja' => 150,
            'created_by' => $user->id,
        ]);
        // Aset B: 10 jam bulan ini
        RentalLog::create([
            'company_id' => $company->id, 'rental_contract_id' => $contractB->id,
            'asset_id' => $assetB->id, 'log_date' => now()->startOfMonth(),
            'hm_awal' => 0, 'hm_akhir' => 10, 'jam_kerja' => 10,
            'created_by' => $user->id,
        ]);

        $rows = $this->service->utilizationRate($company->id);
        // Ascending → B (low) di atas, A di bawah
        $this->assertSame('B-JARANG', $rows[0]['asset_code']);
        $this->assertSame('A-INTENSIF', $rows[1]['asset_code']);
        $this->assertSame('low', $rows[0]['status']);
    }

    // ============================================================
    // Critical stock
    // ============================================================

    public function test_critical_stock_hanya_material_dengan_movement_history(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Material yang sudah ada purchase (stock low)
        $mat = Material::withoutGlobalScopes()->where('company_id', $company->id)->first();
        app(MaterialPurchaseService::class)->record([
            'material_id' => $mat->id,
            'purchase_date' => '2026-08-01',
            'qty' => 3,  // stok kecil, di bawah threshold 5
            'unit_price' => 100_000,
            'payment_method' => 'tunai',
        ]);

        $rows = $this->service->criticalStock($company->id, criticalThreshold: 5);
        $this->assertGreaterThanOrEqual(1, count($rows));
        $codes = array_column($rows, 'code');
        $this->assertContains($mat->code, $codes);
    }

    // ============================================================
    // Multi-tenant isolation
    // ============================================================

    public function test_insight_terisolasi_per_company(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $companyB = $this->createTenant();

        $this->actingAs($userA);

        $asset    = $this->makeAsset($companyA);
        $client   = $this->createClient($companyA);
        $contract = $this->makeContract($companyA, $asset, $client, $userA->id);

        RentalLog::create([
            'company_id' => $companyA->id, 'rental_contract_id' => $contract->id,
            'asset_id' => $asset->id, 'log_date' => now(),
            'hm_awal' => 0, 'hm_akhir' => 5, 'jam_kerja' => 5,
            'created_by' => $userA->id,
        ]);

        // Company A ada jam kerja, Company B kosong
        $statsA = $this->service->stats($companyA->id);
        $statsB = $this->service->stats($companyB->id);

        $this->assertSame(5.0, $statsA['jam_bulan_ini']);
        $this->assertSame(0.0, $statsB['jam_bulan_ini']);
    }
}
