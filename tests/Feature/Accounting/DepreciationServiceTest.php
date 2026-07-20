<?php

namespace Tests\Feature\Accounting;

use App\Models\Asset;
use App\Models\JournalEntry;
use App\Services\Accounting\DepreciationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TST-06 — Feature test DepreciationService idempotent.
 *
 * Cakupan:
 *  - runForCompany(): posting jurnal penyusutan Dr Beban / Cr Akumulasi
 *  - Idempotency: run 2x untuk periode sama tidak dobel post
 *  - Skip: aset non_aktif, purchase_date null, useful_life 0, belum eligible (bulan pembelian)
 *  - Skip: fully depreciated (usia ekonomis habis)
 *  - Nominal bulanan = (purchase_price - salvage_value) / useful_life_months
 *  - Document number deterministik: DEP-{asset_id}-{YYYYMM}
 *  - asset_id tag di journal line untuk P&L per unit
 */
class DepreciationServiceTest extends TestCase
{
    private DepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DepreciationService::class);
    }

    private function createAsset(\App\Models\Company $company, array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'         => $company->id,
            'asset_code'         => 'AST-' . strtoupper(Str::random(4)),
            'name'               => 'Test Asset',
            'type'               => 'dump_truck',
            'purchase_date'      => '2024-01-15',
            'purchase_price'     => 600_000_000,
            'salvage_value'      => 60_000_000,
            'useful_life_months' => 60, // 5 tahun
            'status'             => 'aktif',
        ], $attributes));
    }

    // ============================================================
    // Happy path
    // ============================================================

    public function test_posting_penyusutan_bulanan_dr_beban_cr_akumulasi(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company, [
            'purchase_price'     => 600_000_000,
            'salvage_value'      => 60_000_000,
            'useful_life_months' => 60,
        ]);

        // Monthly = (600jt - 60jt) / 60 = 9jt
        $this->assertSame(9_000_000.0, $asset->monthly_depreciation);

        $result = $this->service->runForCompany($company, 2026, 6);

        $this->assertSame(1, $result['posted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(9_000_000.0, $result['total_amount']);
        $this->assertEmpty($result['errors']);

        // Verifikasi journal
        $doc = sprintf('DEP-%d-202606', $asset->id);
        $journal = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', $doc)
            ->with('lines.account')
            ->first();

        $this->assertNotNull($journal);
        $this->assertSame('penyusutan', $journal->document_type);
        $this->assertTrue($journal->isPosted());
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(9_000_000.0, $journal->total_debit);
        $this->assertCount(2, $journal->lines);

        // Line Dr Beban Penyusutan 552100
        $bebanLine = $journal->lines->where('account.code', '552100')->first();
        $this->assertNotNull($bebanLine);
        $this->assertSame(9_000_000.0, (float) $bebanLine->debit);
        $this->assertSame($asset->id, $bebanLine->asset_id, 'Beban di-tag asset_id untuk P&L per unit');

        // Line Cr Akumulasi Penyusutan 112105 (untuk dump_truck)
        $akumLine = $journal->lines->where('account.code', '112105')->first();
        $this->assertNotNull($akumLine);
        $this->assertSame(9_000_000.0, (float) $akumLine->kredit);
        $this->assertSame($asset->id, $akumLine->asset_id);
    }

    // ============================================================
    // Idempotency — KRITIS
    // ============================================================

    public function test_run_dua_kali_untuk_periode_sama_tidak_dobel_post(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $this->createAsset($company);

        // Run pertama
        $r1 = $this->service->runForCompany($company, 2026, 6);
        $this->assertSame(1, $r1['posted']);
        $this->assertSame(0, $r1['skipped']);

        // Run kedua di periode sama — harus SKIP semua
        $r2 = $this->service->runForCompany($company, 2026, 6);
        $this->assertSame(0, $r2['posted']);
        $this->assertSame(1, $r2['skipped']);
        $this->assertSame(0.0, $r2['total_amount']);

        // Hanya 1 journal penyusutan di DB
        $count = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_type', 'penyusutan')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_document_number_deterministik_DEP_asset_YYYYMM(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company);

        $this->service->runForCompany($company, 2026, 6);

        $expectedDoc = sprintf('DEP-%d-202606', $asset->id);
        $journal = JournalEntry::withoutGlobalScopes()
            ->where('document_number', $expectedDoc)
            ->first();

        $this->assertNotNull($journal);
        $this->assertSame($expectedDoc, $journal->document_number);
    }

    // ============================================================
    // Skip scenarios
    // ============================================================

    public function test_skip_asset_non_aktif(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $this->createAsset($company, ['status' => 'non_aktif']);

        $result = $this->service->runForCompany($company, 2026, 6);

        $this->assertSame(0, $result['posted']);
        // "non_aktif" tidak masuk query awal karena whereIn ['aktif', 'maintenance']
        // → tidak masuk skipped juga (di-filter di query)
        $this->assertSame(0, $result['skipped']);
    }

    public function test_asset_status_maintenance_tetap_disusutkan(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $this->createAsset($company, ['status' => 'maintenance']);

        $result = $this->service->runForCompany($company, 2026, 6);

        // Maintenance = tetap disusutkan (standar akuntansi)
        $this->assertSame(1, $result['posted']);
    }

    public function test_skip_asset_belum_eligible_bulan_pembelian(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Aset dibeli Juni 2026, coba run Juni 2026 — bulan pembelian TIDAK dihitung
        $this->createAsset($company, ['purchase_date' => '2026-06-15']);

        $result = $this->service->runForCompany($company, 2026, 6);
        $this->assertSame(0, $result['posted']);
        $this->assertSame(1, $result['skipped']);

        // Bulan berikutnya (Juli 2026) sudah eligible
        $result = $this->service->runForCompany($company, 2026, 7);
        $this->assertSame(1, $result['posted']);
    }

    public function test_skip_asset_fully_depreciated_usia_ekonomis_habis(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Umur ekonomis 12 bulan, pembelian Jan 2024 → depresiasi Feb 2024 s/d Jan 2025
        // Coba run Feb 2025 → sudah full depreciated
        $this->createAsset($company, [
            'purchase_date'      => '2024-01-15',
            'useful_life_months' => 12,
            'purchase_price'     => 120_000_000,
            'salvage_value'      => 0,
        ]);

        $result = $this->service->runForCompany($company, 2025, 2);
        $this->assertSame(0, $result['posted']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_skip_asset_purchase_date_null(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Buat asset lalu unset purchase_date manual (edge case data lama)
        $asset = $this->createAsset($company);
        $asset->update(['purchase_date' => null]);

        $result = $this->service->runForCompany($company, 2026, 6);
        $this->assertSame(0, $result['posted']);
        $this->assertSame(1, $result['skipped']);
    }

    // ============================================================
    // Multi-tenant isolation
    // ============================================================

    public function test_depresiasi_terisolasi_per_company(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $companyB = $this->createTenant();

        $this->actingAs($userA);
        $this->createAsset($companyA);
        $this->createAsset($companyB);

        $result = $this->service->runForCompany($companyA, 2026, 6);
        $this->assertSame(1, $result['posted']);

        // Company B tetap belum di-depresiasi
        $countB = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyB->id)
            ->where('document_type', 'penyusutan')
            ->count();
        $this->assertSame(0, $countB);
    }

    // ============================================================
    // Preview
    // ============================================================

    public function test_preview_tidak_bikin_journal(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $this->createAsset($company);

        $preview = $this->service->preview($company, 2026, 6);

        $this->assertCount(1, $preview);
        $this->assertTrue($preview[0]['eligible']);
        $this->assertSame(9_000_000.0, $preview[0]['monthly']);

        // Tidak ada journal terbentuk
        $count = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_type', 'penyusutan')
            ->count();
        $this->assertSame(0, $count);
    }
}
