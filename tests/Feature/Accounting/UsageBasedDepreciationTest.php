<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Accounting\DepreciationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BIZ-02 — Feature test Usage-based Depreciation.
 *
 * Cakupan:
 *  - Asset::depreciationPerUnit() menghitung benar untuk per_hour/per_rit/per_day
 *  - Half-day (0.5) di-support untuk per_day
 *  - Aset straight_line: depreciationPerUnit() = 0, monthly_depreciation tetap
 *  - Aset usage-based: monthly_depreciation = 0 (biar cron & laporan tidak salah)
 *  - RunForCompany() SKIP aset usage-based dengan reason yang jelas
 *  - Method dikunci setelah aset punya jurnal DEP-* / DEPUSE-*
 *  - Method boleh diubah selama belum ada jurnal
 *
 * TIDAK di-test di sini (masuk BIZ-03):
 *  - Auto-post DEPUSE-* dari RentalLog/RitLog observer
 *  - Cascade void DEPUSE saat log di-edit/delete
 */
class UsageBasedDepreciationTest extends TestCase
{
    private DepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DepreciationService::class);
    }

    private function createAsset(Company $company, array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'         => $company->id,
            'asset_code'         => 'AST-' . strtoupper(Str::random(4)),
            'name'               => 'Test Asset',
            'type'               => 'excavator',
            'purchase_date'      => '2024-01-15',
            'purchase_price'     => 600_000_000,
            'salvage_value'      => 60_000_000,
            'useful_life_months' => 60,
            'status'             => 'aktif',
        ], $attributes));
    }

    // ============================================================
    // depreciationPerUnit() — per method
    // ============================================================

    public function test_depreciation_per_unit_per_hour(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour,
            'useful_life_hours'   => 10_000, // 10rb jam operasi
            'purchase_price'      => 500_000_000,
            'salvage_value'       => 50_000_000,
        ]);

        // (500jt - 50jt) / 10000 jam = 45.000 per jam
        $this->assertSame(45_000.0, $asset->depreciationPerUnit());
    }

    public function test_depreciation_per_unit_per_rit(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'type'                => 'dump_truck',
            'depreciation_method' => DepreciationMethod::PerRit,
            'useful_life_rits'    => 5000, // 5rb rit muatan
            'purchase_price'      => 300_000_000,
            'salvage_value'       => 30_000_000,
        ]);

        // (300jt - 30jt) / 5000 = 54.000 per rit
        $this->assertSame(54_000.0, $asset->depreciationPerUnit());
    }

    public function test_depreciation_per_unit_per_day_supports_fraksional(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerDay,
            'useful_life_days'    => 1825, // 5 tahun × 365 hari
            'purchase_price'      => 200_000_000,
            'salvage_value'       => 20_000_000,
        ]);

        // (200jt - 20jt) / 1825 = 98630.1370 → round 4 desimal
        $this->assertSame(98_630.137, $asset->depreciationPerUnit());

        // Simulasi half-day: 0.5 × per-day = penyusutan setengah hari
        // (dipakai nanti di BIZ-03 observer, tapi kita test formulasi di sini)
        $halfDay = round(0.5 * $asset->depreciationPerUnit(), 2);
        $this->assertSame(49_315.07, $halfDay);
    }

    public function test_straight_line_depreciation_per_unit_returns_zero(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::StraightLine,
            'useful_life_months'  => 60,
        ]);

        $this->assertSame(0.0, $asset->depreciationPerUnit(),
            'Straight-line tidak punya per-unit — depresiasi bulanan via cron'
        );
    }

    public function test_depreciation_per_unit_zero_ketika_useful_life_belum_diisi(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour,
            'useful_life_hours'   => null, // belum diisi
        ]);

        $this->assertSame(0.0, $asset->depreciationPerUnit(),
            'Guard: umur ekonomis usage null → return 0 (bukan division-by-zero)'
        );
    }

    public function test_depreciation_per_unit_zero_ketika_salvage_lebih_besar_dari_harga_beli(): void
    {
        $company = $this->createTenant();

        // Edge case data salah: salvage > purchase → depreciable_base negatif
        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour,
            'useful_life_hours'   => 10_000,
            'purchase_price'      => 100_000_000,
            'salvage_value'       => 150_000_000,
        ]);

        $this->assertSame(0.0, $asset->depreciationPerUnit(),
            'Guard: salvage > purchase → return 0, jangan post penyusutan negatif'
        );
    }

    // ============================================================
    // monthly_depreciation attribute — biar cron & report konsisten
    // ============================================================

    public function test_monthly_depreciation_return_zero_kalau_usage_based(): void
    {
        $company = $this->createTenant();

        $straight = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::StraightLine,
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'useful_life_months'  => 60,
        ]);
        $this->assertSame(9_000_000.0, $straight->monthly_depreciation);

        $perHour = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour,
            'useful_life_hours'   => 10_000,
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'useful_life_months'  => 60, // walau diisi, harus di-abaikan
        ]);
        $this->assertSame(0.0, $perHour->monthly_depreciation,
            'Aset usage-based tidak boleh punya monthly — cegah double-count di laporan'
        );
    }

    // ============================================================
    // Integrasi ke DepreciationService (cron bulanan skip usage-based)
    // ============================================================

    public function test_run_for_company_skip_aset_usage_based(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // 1 aset straight_line (posted 1 journal)
        $straight = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::StraightLine,
        ]);

        // 2 aset usage-based (skip)
        $perHour = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour,
            'useful_life_hours'   => 10_000,
        ]);
        $perRit = $this->createAsset($company, [
            'type'                => 'dump_truck',
            'depreciation_method' => DepreciationMethod::PerRit,
            'useful_life_rits'    => 5000,
        ]);

        $result = $this->service->runForCompany($company, 2026, 6);

        $this->assertSame(1, $result['posted'],
            'Hanya straight_line yang boleh ke-post monthly'
        );
        $this->assertSame(2, $result['skipped'],
            'Dua aset usage-based harus di-skip'
        );

        // Verifikasi jurnal — hanya ada 1 untuk straight_line asset
        $depCount = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_type', 'penyusutan')
            ->count();
        $this->assertSame(1, $depCount);

        // Aset usage-based tidak boleh punya DEP-* apapun
        foreach ([$perHour, $perRit] as $asset) {
            $has = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('document_number', 'like', "DEP-{$asset->id}-%")
                ->exists();
            $this->assertFalse($has,
                "Aset usage-based [{$asset->asset_code}] tidak boleh ada jurnal DEP-*"
            );
        }
    }

    public function test_preview_flag_aset_usage_based_sebagai_non_eligible_dengan_reason(): void
    {
        $company = $this->createTenant();

        $this->createAsset($company, [
            'asset_code'          => 'EXC-01',
            'depreciation_method' => DepreciationMethod::PerHour,
            'useful_life_hours'   => 10_000,
        ]);

        $preview = $this->service->preview($company, 2026, 6);
        $this->assertCount(1, $preview);

        $row = $preview[0];
        $this->assertFalse($row['eligible']);
        $this->assertStringContainsString('per_hour', $row['reason']);
        $this->assertStringContainsString('per log usage', $row['reason']);
    }

    // ============================================================
    // Method lock — Q6 sprint plan
    // ============================================================

    public function test_method_boleh_diubah_selama_belum_ada_jurnal_dep(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::StraightLine,
        ]);

        // Belum ada journal → boleh switch
        $asset->update(['depreciation_method' => DepreciationMethod::PerHour->value, 'useful_life_hours' => 10_000]);

        $this->assertSame(DepreciationMethod::PerHour, $asset->fresh()->depreciation_method);
    }

    public function test_method_di_kunci_setelah_ada_jurnal_dep_posted(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // 1. Bikin aset straight_line
        $asset = $this->createAsset($company, [
            'purchase_date'       => '2024-01-15',
            'depreciation_method' => DepreciationMethod::StraightLine,
        ]);

        // 2. Post depresiasi bulanan → sekarang ada DEP-* journal
        $this->service->runForCompany($company, 2026, 6);
        $this->assertTrue($asset->fresh()->hasPostedDepreciationJournal());

        // 3. Coba ubah method → harus throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tidak bisa ubah metode depresiasi');

        $asset->update(['depreciation_method' => DepreciationMethod::PerHour->value]);
    }

    public function test_field_lain_tetap_boleh_diubah_walau_method_lock_aktif(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company);
        $this->service->runForCompany($company, 2026, 6);

        // Update field lain (bukan depreciation_method) → tidak di-guard
        $asset->update(['name' => 'Excavator PC200 (renamed)']);

        $this->assertSame('Excavator PC200 (renamed)', $asset->fresh()->name);
    }

    // ============================================================
    // Isolasi tenant tetap terjaga
    // ============================================================

    public function test_lock_check_pakai_scope_company_bukan_global(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $this->actingAs($userA);

        // Aset A dep posted → hasPostedDepreciationJournal true
        $assetA = $this->createAsset($companyA);
        $this->service->runForCompany($companyA, 2026, 6);
        $this->assertTrue($assetA->fresh()->hasPostedDepreciationJournal());

        // Aset B (company beda) → tidak boleh terpengaruh
        $assetB = $this->createAsset($companyB);
        $this->assertFalse($assetB->fresh()->hasPostedDepreciationJournal(),
            'Method lock check harus per-company, bukan global'
        );

        // Aset B boleh ubah method
        $assetB->update([
            'depreciation_method' => DepreciationMethod::PerHour->value,
            'useful_life_hours'   => 10_000,
        ]);
        $this->assertSame(DepreciationMethod::PerHour, $assetB->fresh()->depreciation_method);
    }
}
