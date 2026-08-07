<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Company;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\AssetCostPerUnitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BIZ-05 — Feature test Laporan Biaya Operasional per Unit.
 *
 * Cakupan:
 *  - Aggregasi cost + revenue per aset dari ledger (tag asset_id)
 *  - Usage dari log (jam dari RentalLog, rit dari RitLog)
 *  - Channel detection: method > type
 *  - cost/unit, revenue/unit, margin/unit hitung benar
 *  - Baris merugi → is_losing flag
 *  - Filter only_losing hilangkan yang untung
 *  - Sort: rugi paling parah di atas
 *  - Isolasi tenant
 */
class AssetCostPerUnitTest extends TestCase
{
    private AssetCostPerUnitService $service;
    private int $year;
    private int $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AssetCostPerUnitService::class);
        $this->year  = 2026;
        $this->month = 8;
    }

    private function createAsset(Company $company, array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'          => $company->id,
            'asset_code'          => 'AST-' . strtoupper(Str::random(4)),
            'name'                => 'Test Asset',
            'type'                => 'excavator',
            'purchase_date'       => '2024-01-15',
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'depreciation_method' => DepreciationMethod::PerHour->value,
            'useful_life_hours'   => 10_000,
            'status'              => 'aktif',
        ], $attributes));
    }

    private function makeAlatSajaContract(Company $company, Asset $asset, Client $client): RentalContract
    {
        $creator = auth()->id() ?? $this->createTenantUser($company)->id;

        return RentalContract::create([
            'company_id'         => $company->id,
            'contract_number'    => 'RC-' . strtoupper(Str::random(4)),
            'client_id'          => $client->id,
            'asset_id'           => $asset->id,
            'tipe_rental'        => 'alat_saja',
            'include_bbm'        => false,
            'include_operator'   => false,
            'tarif_per_jam'      => 150_000,
            'billed_jam'         => 0,
            'status'             => 'aktif',
            'started_at'         => '2026-01-01',
            'created_by'         => $creator,
        ]);
    }

    private function makeArmadaContract(Company $company, Client $client): ArmadaContract
    {
        $creator = auth()->id() ?? $this->createTenantUser($company)->id;

        return ArmadaContract::create([
            'company_id'         => $company->id,
            'contract_number'    => 'AC-' . strtoupper(Str::random(4)),
            'client_id'          => $client->id,
            'tipe_kontrak'       => 'alat_saja',
            'include_bbm'        => false,
            'include_operator'   => false,
            'route_description'  => 'Route uji',
            'tarif_per_rit'      => 200_000,
            'billed_rit'         => 0,
            'status'             => 'aktif',
            'started_at'         => '2026-01-01',
            'created_by'         => $creator,
        ]);
    }

    /**
     * Post manual journal cost/revenue tagged asset_id di period test.
     * Bypass observer supaya isolasi test tetap ke logic AssetCostPerUnitService.
     */
    private function postManualJournal(
        Company $company,
        User $user,
        Asset $asset,
        string $accountCode,
        float $debit,
        float $kredit,
        ?Carbon $date = null,
    ): void {
        $date ??= Carbon::create($this->year, $this->month, 15);
        $account = $this->postableAccount($company, $accountCode);
        // Counter-side: kalau cost (debit) → kas kredit. Kalau revenue (kredit) → piutang debit.
        $counter = $debit > 0
            ? $this->postableAccount($company, '111100') // Kas
            : $this->postableAccount($company, '111200'); // Piutang

        $counterDebit = $debit > 0 ? 0 : ($kredit > 0 ? $kredit : 0);
        $counterKredit = $debit > 0 ? $debit : 0;

        $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $account->id, 'asset_id' => $asset->id, 'debit' => $debit, 'kredit' => $kredit],
                ['account_id' => $counter->id, 'debit' => $counterDebit, 'kredit' => $counterKredit],
            ],
            [
                'status'       => 'posted',
                'posted_by'    => $user->id,
                'posted_at'    => now(),
                'total_amount' => max($debit, $kredit),
            ],
            $date,
        );
    }

    // ============================================================
    // Aggregasi cost + revenue + usage
    // ============================================================

    public function test_report_agregasi_cost_revenue_dan_usage_per_asset(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->createAsset($company);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);

        // Cost tagged asset: BBM 3jt + Gaji 2jt = 5jt total (kode 551100 + 552200)
        $this->postManualJournal($company, $user, $asset, '551100', 3_000_000, 0);
        $this->postManualJournal($company, $user, $asset, '552200', 2_000_000, 0);

        // Revenue tagged asset: 8jt (kode 441100 pendapatan rental)
        $this->postManualJournal($company, $user, $asset, '441100', 0, 8_000_000);

        // Usage: 20 jam dari 2 RentalLog (10 jam × 2)
        // Aset per_hour → DEPUSE observer akan post juga; skip via kontrak alat_saja
        // sudah handle BBK, tapi DEPUSE tetap post → cost bertambah 10 × 54rb = 540rb per log
        // Total DEPUSE untuk 2 log = 1.08jt
        $this->makeRentalLog($company, $contract, $user, ['jam_kerja' => 10, 'hm_awal' => 100, 'hm_akhir' => 110]);
        $this->makeRentalLog($company, $contract, $user, [
            'jam_kerja' => 10, 'hm_awal' => 110, 'hm_akhir' => 120,
            'log_date' => '2026-08-20',
        ]);

        $report = $this->service->getReport($company->id, $this->year, $this->month);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertNotNull($row);
        $this->assertSame(20.0, $row['usage'], 'Usage total 20 jam dari 2 log');
        $this->assertSame('jam', $row['channel']);
        $this->assertSame(8_000_000.0, $row['revenue']);
        // Cost = 3jt (BBM) + 2jt (gaji) + 1.08jt (DEPUSE) = 6.08jt
        $this->assertSame(6_080_000.0, $row['cost_total']);
        $this->assertSame(1_920_000.0, $row['net']);

        // cost/jam = 6.08jt / 20 = 304.000
        $this->assertSame(304_000.0, $row['cost_per_unit']);
        // revenue/jam = 8jt / 20 = 400.000
        $this->assertSame(400_000.0, $row['revenue_per_unit']);
        // margin/jam = 400rb - 304rb = 96.000 (untung)
        $this->assertSame(96_000.0, $row['margin_per_unit']);
        $this->assertFalse($row['is_losing']);
    }

    private function makeRentalLog(
        Company $company,
        RentalContract $contract,
        User $user,
        array $attributes = [],
    ): RentalLog {
        return RentalLog::create(array_merge([
            'company_id'         => $company->id,
            'rental_contract_id' => $contract->id,
            'asset_id'           => $contract->asset_id,
            'log_date'           => sprintf('%04d-%02d-05', $this->year, $this->month),
            'hm_awal'            => 100,
            'hm_akhir'            => 110,
            'jam_kerja'          => 10,
            'created_by'         => $user->id,
        ], $attributes));
    }

    // ============================================================
    // Losing flag + sort ascending margin
    // ============================================================

    public function test_margin_negatif_flag_is_losing(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Aset straight_line supaya observer DEPUSE tidak nambah cost noise;
        // yang di-uji: aggregation service (bukan observer chain).
        $slAttrs = [
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_hours'   => null,
            'useful_life_months'  => 60,
        ];

        // Aset A: revenue 5jt, cost 10jt → margin negatif
        $assetA = $this->createAsset($company, array_merge($slAttrs, ['asset_code' => 'A-LOSS']));
        $clientA   = $this->createClient($company);
        $contractA = $this->makeAlatSajaContract($company, $assetA, $clientA);

        $this->postManualJournal($company, $user, $assetA, '551100', 10_000_000, 0);
        $this->postManualJournal($company, $user, $assetA, '441100', 0, 5_000_000);
        $this->makeRentalLog($company, $contractA, $user, ['jam_kerja' => 100, 'hm_awal' => 1, 'hm_akhir' => 101]);

        // Aset B: revenue 10jt, cost 5jt → untung
        $assetB = $this->createAsset($company, array_merge($slAttrs, ['asset_code' => 'B-PROFIT']));
        $clientB   = $this->createClient($company);
        $contractB = $this->makeAlatSajaContract($company, $assetB, $clientB);

        $this->postManualJournal($company, $user, $assetB, '551100', 5_000_000, 0);
        $this->postManualJournal($company, $user, $assetB, '441100', 0, 10_000_000);
        $this->makeRentalLog($company, $contractB, $user, ['jam_kerja' => 100, 'hm_awal' => 1, 'hm_akhir' => 101]);

        $report = $this->service->getReport($company->id, $this->year, $this->month);

        $rowA = collect($report['rows'])->firstWhere('asset_code', 'A-LOSS');
        $rowB = collect($report['rows'])->firstWhere('asset_code', 'B-PROFIT');

        $this->assertTrue($rowA['is_losing']);
        $this->assertFalse($rowB['is_losing']);

        // Sort — rugi paling parah harus di baris pertama
        $this->assertSame('A-LOSS', $report['rows'][0]['asset_code']);

        $this->assertSame(1, $report['losing_count']);
    }

    public function test_filter_only_losing_menghilangkan_yang_untung(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $slAttrs = [
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_hours'   => null,
            'useful_life_months'  => 60,
        ];

        $assetA = $this->createAsset($company, array_merge($slAttrs, ['asset_code' => 'A-LOSS']));
        $clientA   = $this->createClient($company);
        $contractA = $this->makeAlatSajaContract($company, $assetA, $clientA);
        $this->postManualJournal($company, $user, $assetA, '551100', 10_000_000, 0);
        $this->postManualJournal($company, $user, $assetA, '441100', 0, 5_000_000);
        $this->makeRentalLog($company, $contractA, $user, ['jam_kerja' => 100, 'hm_awal' => 1, 'hm_akhir' => 101]);

        $assetB = $this->createAsset($company, array_merge($slAttrs, ['asset_code' => 'B-PROFIT']));
        $clientB   = $this->createClient($company);
        $contractB = $this->makeAlatSajaContract($company, $assetB, $clientB);
        $this->postManualJournal($company, $user, $assetB, '551100', 5_000_000, 0);
        $this->postManualJournal($company, $user, $assetB, '441100', 0, 10_000_000);
        $this->makeRentalLog($company, $contractB, $user, ['jam_kerja' => 100, 'hm_awal' => 1, 'hm_akhir' => 101]);

        $report = $this->service->getReport($company->id, $this->year, $this->month, ['only_losing' => true]);

        $codes = array_column($report['rows'], 'asset_code');
        $this->assertContains('A-LOSS', $codes);
        $this->assertNotContains('B-PROFIT', $codes);
        $this->assertCount(1, $report['rows']);
    }

    // ============================================================
    // Channel detection
    // ============================================================

    public function test_channel_per_hour_pakai_jam(): void
    {
        $company = $this->createTenant();
        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour->value,
            'useful_life_hours'   => 10_000,
        ]);

        $report = $this->service->getReport($company->id, $this->year, $this->month);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);
        $this->assertSame('jam', $row['channel']);
    }

    public function test_channel_per_rit_pakai_rit(): void
    {
        $company = $this->createTenant();
        $asset = $this->createAsset($company, [
            'type'                => 'dump_truck',
            'depreciation_method' => DepreciationMethod::PerRit->value,
            'useful_life_rits'    => 5000,
        ]);

        $report = $this->service->getReport($company->id, $this->year, $this->month);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);
        $this->assertSame('rit', $row['channel']);
    }

    public function test_channel_fallback_ke_type_untuk_straight_line(): void
    {
        $company = $this->createTenant();

        $dt = $this->createAsset($company, [
            'asset_code'          => 'DT-SL',
            'type'                => 'dump_truck',
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_hours'   => null,
            'useful_life_months'  => 60,
        ]);
        $ex = $this->createAsset($company, [
            'asset_code'          => 'EX-SL',
            'type'                => 'excavator',
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_hours'   => null,
            'useful_life_months'  => 60,
        ]);

        $report = $this->service->getReport($company->id, $this->year, $this->month);
        $rowDt = collect($report['rows'])->firstWhere('asset_code', 'DT-SL');
        $rowEx = collect($report['rows'])->firstWhere('asset_code', 'EX-SL');

        $this->assertSame('rit', $rowDt['channel']);
        $this->assertSame('jam', $rowEx['channel']);
    }

    // ============================================================
    // RitLog usage — cross channel
    // ============================================================

    public function test_ritlog_dump_truck_agregasi_rit(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company, [
            'asset_code'          => 'DT-01',
            'type'                => 'dump_truck',
            'depreciation_method' => DepreciationMethod::PerRit->value,
            'useful_life_rits'    => 5000,
            'purchase_price'      => 300_000_000,
            'salvage_value'       => 30_000_000, // per_unit = 54rb/rit
        ]);
        $client   = $this->createClient($company);
        $contract = $this->makeArmadaContract($company, $client);

        RitLog::create([
            'company_id'         => $company->id,
            'armada_contract_id' => $contract->id,
            'asset_id'           => $asset->id,
            'log_date'           => sprintf('%04d-%02d-05', $this->year, $this->month),
            'rit_count'          => 15,
            'created_by'         => $user->id,
        ]);

        // Manual revenue 6jt
        $this->postManualJournal($company, $user, $asset, '441100', 0, 6_000_000);

        $report = $this->service->getReport($company->id, $this->year, $this->month);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertSame(15.0, $row['usage']);
        $this->assertSame('rit', $row['channel']);
        $this->assertSame(6_000_000.0, $row['revenue']);
        // Cost = DEPUSE only (15 × 54rb = 810rb)
        $this->assertSame(810_000.0, $row['cost_total']);
        // 6jt / 15 rit = 400.000/rit
        $this->assertSame(400_000.0, $row['revenue_per_unit']);
        // 810rb / 15 = 54.000/rit
        $this->assertSame(54_000.0, $row['cost_per_unit']);
        // Margin = 346.000/rit (profit)
        $this->assertSame(346_000.0, $row['margin_per_unit']);
    }

    // ============================================================
    // Multi-tenant
    // ============================================================

    public function test_report_terisolasi_per_tenant(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();

        $this->createAsset($companyA, ['asset_code' => 'A-ONLY']);
        $this->createAsset($companyB, ['asset_code' => 'B-ONLY']);

        $report = $this->service->getReport($companyA->id, $this->year, $this->month);
        $codes = array_column($report['rows'], 'asset_code');

        $this->assertContains('A-ONLY', $codes);
        $this->assertNotContains('B-ONLY', $codes);
    }

    // ============================================================
    // Period boundary
    // ============================================================

    public function test_log_di_bulan_lain_tidak_masuk_periode_ini(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->createAsset($company);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);

        // 10 jam di bulan target
        $this->makeRentalLog($company, $contract, $user, [
            'jam_kerja' => 10, 'hm_awal' => 1, 'hm_akhir' => 11,
            'log_date' => sprintf('%04d-%02d-05', $this->year, $this->month),
        ]);
        // 20 jam di bulan sebelum — TIDAK boleh masuk laporan Agustus
        $prev = Carbon::create($this->year, $this->month, 1)->subMonthNoOverflow();
        $this->makeRentalLog($company, $contract, $user, [
            'jam_kerja' => 20, 'hm_awal' => 20, 'hm_akhir' => 40,
            'log_date' => $prev->copy()->day(15)->toDateString(),
        ]);

        $report = $this->service->getReport($company->id, $this->year, $this->month);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertSame(10.0, $row['usage'], 'Hanya jam bulan Agustus yang counted');
    }
}
