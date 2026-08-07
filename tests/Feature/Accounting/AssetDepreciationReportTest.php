<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\Company;
use App\Services\Accounting\AssetDepreciationReportService;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BIZ-04 — Feature test Laporan Penyusutan per Aset.
 *
 * Cakupan:
 *  - Akumulasi refleksi ledger (bukan kalkulasi ideal Asset->monthly × N)
 *  - Void jurnal → akumulasi otomatis berkurang (via pembalik net-off)
 *  - Filter type / business_unit / method
 *  - Sisa umur straight_line akurat
 *  - Fully depreciated flag
 *  - Multi-tenant isolation
 *  - Usage-based aset tampil dengan angka nol kalau belum ada log
 */
class AssetDepreciationReportTest extends TestCase
{
    private AssetDepreciationReportService $service;
    private DepreciationService $dep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AssetDepreciationReportService::class);
        $this->dep     = app(DepreciationService::class);
    }

    private function createAsset(Company $company, array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'          => $company->id,
            'asset_code'          => 'AST-' . strtoupper(Str::random(4)),
            'name'                => 'Test Asset',
            'type'                => 'dump_truck',
            'purchase_date'       => '2024-01-15',
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'useful_life_months'  => 60,
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'status'              => 'aktif',
        ], $attributes));
    }

    // ============================================================
    // Akumulasi = source of truth ledger
    // ============================================================

    public function test_akumulasi_refleksi_ledger_bukan_kalkulasi_ideal(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company);
        // Monthly = (600jt - 60jt) / 60 = 9jt

        // Post cuma 2 bulan (Feb & Mar 2026), skip yang lain
        $this->dep->runForCompany($company, 2026, 2);
        $this->dep->runForCompany($company, 2026, 3);

        $report = $this->service->getReport($company->id, 2026, 12);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        // Ledger = 2 × 9jt = 18jt (bukan 9 bulan × 9jt = 81jt kalau kalkulasi ideal)
        $this->assertSame(18_000_000.0, $row['akumulasi'],
            'Akumulasi harus dari ledger aktual, bukan kalkulasi ideal months_elapsed × monthly'
        );
        $this->assertSame(600_000_000.0 - 18_000_000.0, $row['nilai_buku']);
    }

    public function test_void_journal_mengurangi_akumulasi_via_pembalik(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company);
        $this->dep->runForCompany($company, 2026, 2); // +9jt
        $this->dep->runForCompany($company, 2026, 3); // +9jt

        // Void jurnal Feb 2026
        $febJournal = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', sprintf('DEP-%d-202602', $asset->id))
            ->first();
        $this->assertNotNull($febJournal);

        app(JournalService::class)->void($febJournal, 'Test void');

        // Sekarang akumulasi = 9jt (Mar) saja
        $report = $this->service->getReport($company->id, 2026, 12);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertSame(9_000_000.0, $row['akumulasi'],
            'Pembalik void harus otomatis net-off dari akumulasi'
        );
    }

    // ============================================================
    // Sisa umur straight_line
    // ============================================================

    public function test_sisa_umur_straight_line_terhitung_benar(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'purchase_date'      => '2024-01-15',
            'useful_life_months' => 60,
        ]);

        // Depresiasi mulai Feb 2024. Per Des 2026 = 35 bulan sudah elapsed (Feb 2024 → Des 2026)
        // Actually: Feb 2024, Mar 2024, ... Des 2026. Feb→Dec 2024 = 11, +12 (2025) +12 (2026) = 35 bulan.
        // Sisa = 60 - 35 = 25 bulan.
        $report = $this->service->getReport($company->id, 2026, 12);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertSame(25.0, $row['sisa_umur']);
        $this->assertSame('bulan', $row['sisa_umur_unit']);
        $this->assertSame(9_000_000.0, $row['next_month_dep']);
    }

    public function test_fully_depreciated_flag_ketika_book_value_tinggal_residu(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company, [
            'purchase_date'      => '2024-01-15',
            'useful_life_months' => 12,
            'purchase_price'     => 120_000_000,
            'salvage_value'      => 0,
        ]);

        // Post depresiasi Feb 2024 – Jan 2025 (12 bulan × 10jt)
        for ($m = 2; $m <= 12; $m++) {
            $this->dep->runForCompany($company, 2024, $m);
        }
        $this->dep->runForCompany($company, 2025, 1);

        $report = $this->service->getReport($company->id, 2025, 6);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertSame(120_000_000.0, $row['akumulasi']);
        $this->assertSame(0.0, $row['nilai_buku']);
        $this->assertTrue($row['fully_depreciated']);
        $this->assertSame(0.0, $row['sisa_umur']);
    }

    // ============================================================
    // Filter
    // ============================================================

    public function test_filter_by_type(): void
    {
        $company = $this->createTenant();

        $this->createAsset($company, ['type' => 'dump_truck',            'asset_code' => 'DT-1']);
        $this->createAsset($company, ['type' => 'excavator',             'asset_code' => 'EX-1']);
        $this->createAsset($company, ['type' => 'kendaraan_operasional', 'asset_code' => 'KO-1']);

        $report = $this->service->getReport($company->id, 2026, 6, ['type' => 'excavator']);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('EX-1', $report['rows'][0]['asset_code']);
    }

    public function test_filter_by_method(): void
    {
        $company = $this->createTenant();

        $this->createAsset($company, [
            'asset_code'          => 'SL-1',
            'depreciation_method' => DepreciationMethod::StraightLine->value,
        ]);
        $this->createAsset($company, [
            'asset_code'          => 'PH-1',
            'depreciation_method' => DepreciationMethod::PerHour->value,
            'useful_life_hours'   => 10_000,
        ]);

        $report = $this->service->getReport($company->id, 2026, 6, ['method' => 'per_hour']);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('PH-1', $report['rows'][0]['asset_code']);
    }

    // ============================================================
    // Usage-based tampil, tapi angka nol kalau belum ada log
    // ============================================================

    public function test_asset_usage_based_tampil_dengan_metode_label_dan_akumulasi_nol(): void
    {
        $company = $this->createTenant();

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour->value,
            'useful_life_hours'   => 10_000,
            'useful_life_months'  => 0, // usage-based tidak pakai months
        ]);

        $report = $this->service->getReport($company->id, 2026, 6);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertSame('Per Jam Kerja', $row['method_label']);
        $this->assertSame(0.0, $row['akumulasi'], 'Belum ada log usage → akumulasi 0');
        $this->assertSame((float) $asset->purchase_price, $row['nilai_buku']);
        // Sisa umur = useful_life_hours penuh karena belum ada usage
        $this->assertSame(10_000.0, $row['sisa_umur']);
        $this->assertSame('jam', $row['sisa_umur_unit']);
        // Usage-based tidak punya "next month" — always 0
        $this->assertSame(0.0, $row['next_month_dep']);
    }

    // ============================================================
    // Totals
    // ============================================================

    public function test_totals_menjumlahkan_semua_baris(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $a1 = $this->createAsset($company, [
            'asset_code'         => 'A1',
            'purchase_price'     => 500_000_000,
            'salvage_value'      => 50_000_000,
            'useful_life_months' => 60,
        ]);
        $a2 = $this->createAsset($company, [
            'asset_code'         => 'A2',
            'purchase_price'     => 300_000_000,
            'salvage_value'      => 30_000_000,
            'useful_life_months' => 60,
        ]);

        // Post 1 bulan untuk masing-masing
        // Monthly A1 = (500-50)/60 = 7.5jt ; A2 = (300-30)/60 = 4.5jt
        $this->dep->runForCompany($company, 2026, 2);

        $report = $this->service->getReport($company->id, 2026, 6);

        $this->assertSame(800_000_000.0, $report['totals']['purchase_price']);
        $this->assertSame(12_000_000.0, $report['totals']['akumulasi']);
        $this->assertSame(788_000_000.0, $report['totals']['nilai_buku']);
        // Est bulan depan = 7.5jt + 4.5jt = 12jt (dua aset masih dalam umur ekonomis)
        $this->assertSame(12_000_000.0, $report['totals']['next_month_dep']);
    }

    // ============================================================
    // Multi-tenant isolation
    // ============================================================

    public function test_report_terisolasi_per_tenant(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();

        $this->createAsset($companyA, ['asset_code' => 'A-ONLY']);
        $this->createAsset($companyB, ['asset_code' => 'B-ONLY']);

        $reportA = $this->service->getReport($companyA->id, 2026, 6);
        $codesA  = array_column($reportA['rows'], 'asset_code');

        $this->assertContains('A-ONLY', $codesA);
        $this->assertNotContains('B-ONLY', $codesA);
    }

    // ============================================================
    // Konsistensi dengan Neraca — angka akumulasi harus sama
    // ============================================================

    public function test_akumulasi_report_match_dengan_balance_sheet(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $this->createAsset($company);
        $this->dep->runForCompany($company, 2026, 2);
        $this->dep->runForCompany($company, 2026, 3);

        // Laporan penyusutan
        $depReport = $this->service->getReport($company->id, 2026, 6);
        $totalAkumFromDep = $depReport['totals']['akumulasi'];

        // Neraca — cari total akumulasi penyusutan
        $balanceSheet = app(\App\Services\Accounting\BalanceSheetService::class)
            ->getReport($company->id, 2026, 6);

        $totalAkumFromNeraca = $balanceSheet['asetTetap']
            ->filter(fn ($r) => $r->normal_balance === 'kredit')
            ->sum('saldo_kredit');

        $this->assertSame($totalAkumFromDep, (float) $totalAkumFromNeraca,
            'Total akumulasi di laporan penyusutan HARUS sama dengan section akumulasi di Neraca'
        );
    }
}
