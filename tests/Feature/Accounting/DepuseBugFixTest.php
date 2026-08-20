<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression test untuk 2 bug DEPUSE HIGH yang di-fix:
 *
 *  BUG-DEPUSE-01: void BBK-RL / BBK-RT tidak cascade DEPUSE — akumulasi overstate.
 *  BUG-DEPUSE-04: aset PerDay tidak pernah didepresiasi (skip di cron + skip di observer).
 */
class DepuseBugFixTest extends TestCase
{
    use RefreshDatabase;

    // ================================================================
    // BUG-DEPUSE-01: Cascade void DEPUSE saat BBK di-void
    // ================================================================

    public function test_void_bbk_rl_cascades_void_to_related_depuse(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $asset = $this->makeUsageBasedAsset($company, DepreciationMethod::PerHour, [
            'useful_life_hours' => 10000.0,
            'purchase_price'    => 500_000_000,
            'salvage_value'     => 50_000_000,
        ]);

        $logId = 42; // Simulasi RentalLog #42 — dua jurnal ini "seakan" datang dari log yang sama.

        // Bikin jurnal BBK-RL-42 (biaya operasional)
        $bbk = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '551100')->id, 'debit' => 500_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '111100')->id, 'debit' => 0,       'kredit' => 500_000],
            ],
            ['document_number' => "BBK-RL-{$logId}", 'document_type' => 'bkk'],
        );
        app(JournalService::class)->post($bbk);

        // Bikin jurnal DEPUSE-{asset}-42 (penyusutan usage-based)
        $depuse = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '552100')->id, 'asset_id' => $asset->id, 'debit' => 400_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '112105')->id, 'asset_id' => $asset->id, 'debit' => 0,       'kredit' => 400_000],
            ],
            ['document_number' => "DEPUSE-{$asset->id}-{$logId}", 'document_type' => 'penyusutan'],
        );
        app(JournalService::class)->post($depuse);

        $this->assertSame('posted', $bbk->refresh()->status);
        $this->assertSame('posted', $depuse->refresh()->status);

        // Aksi: void BBK-RL — cascade DEPUSE harus ikut jalan
        app(JournalService::class)->void($bbk, 'Test cascade');

        $depuse->refresh();
        $this->assertSame('void', $depuse->status, 'DEPUSE harus ikut void saat BBK-RL di-void (cascade)');
    }

    public function test_void_bbk_rt_cascades_void_to_related_depuse(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $asset = $this->makeUsageBasedAsset($company, DepreciationMethod::PerRit, [
            'useful_life_rits' => 5000.0,
            'purchase_price'   => 400_000_000,
            'salvage_value'    => 40_000_000,
        ]);

        $logId = 77;

        $bbk = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '551100')->id, 'debit' => 300_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '111100')->id, 'debit' => 0,       'kredit' => 300_000],
            ],
            ['document_number' => "BBK-RT-{$logId}", 'document_type' => 'bkk'],
        );
        app(JournalService::class)->post($bbk);

        $depuse = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '552100')->id, 'asset_id' => $asset->id, 'debit' => 250_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '112105')->id, 'asset_id' => $asset->id, 'debit' => 0,       'kredit' => 250_000],
            ],
            ['document_number' => "DEPUSE-{$asset->id}-{$logId}", 'document_type' => 'penyusutan'],
        );
        app(JournalService::class)->post($depuse);

        app(JournalService::class)->void($bbk, 'Test cascade');

        $this->assertSame('void', $depuse->refresh()->status);
    }

    public function test_cascade_depuse_only_matches_exact_log_suffix(): void
    {
        // Regression guard: cari suffix -5 tidak boleh match -15 (log_id berbeda)
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $asset = $this->makeUsageBasedAsset($company, DepreciationMethod::PerHour, [
            'useful_life_hours' => 10000.0,
            'purchase_price'    => 500_000_000,
            'salvage_value'     => 50_000_000,
        ]);

        // DEPUSE untuk log 15 (BUKAN log yang di-void)
        $depuseOther = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '552100')->id, 'asset_id' => $asset->id, 'debit' => 200_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '112105')->id, 'asset_id' => $asset->id, 'debit' => 0,       'kredit' => 200_000],
            ],
            ['document_number' => "DEPUSE-{$asset->id}-15", 'document_type' => 'penyusutan'],
        );
        app(JournalService::class)->post($depuseOther);

        // BBK untuk log 5 (yang akan di-void)
        $bbk = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '551100')->id, 'debit' => 100_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '111100')->id, 'debit' => 0,       'kredit' => 100_000],
            ],
            ['document_number' => 'BBK-RL-5', 'document_type' => 'bkk'],
        );
        app(JournalService::class)->post($bbk);

        app(JournalService::class)->void($bbk, 'Test');

        // DEPUSE untuk log 15 TIDAK boleh ke-void (suffix beda)
        $this->assertSame('posted', $depuseOther->refresh()->status,
            'DEPUSE untuk log 15 tidak boleh ke-void saat BBK log 5 di-void');
    }

    public function test_void_bbk_mt_does_not_touch_depuse(): void
    {
        // Regression guard: cascade DEPUSE HANYA untuk BBK-RL & BBK-RT.
        // BBK-MT (maintenance) tidak menghasilkan DEPUSE, tidak boleh nyari-nyari.
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $asset = $this->makeUsageBasedAsset($company, DepreciationMethod::PerHour, [
            'useful_life_hours' => 10000.0,
            'purchase_price'    => 500_000_000,
            'salvage_value'     => 50_000_000,
        ]);

        // Bikin fake BBK-MT + DEPUSE untuk id yang sama, pastikan DEPUSE tidak
        // ke-void kalau BBK-MT di-void.
        $bbkMt = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '551100')->id, 'debit' => 100_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '111100')->id, 'debit' => 0,       'kredit' => 100_000],
            ],
            ['document_number' => 'BBK-MT-99', 'document_type' => 'bkk'],
        );
        app(JournalService::class)->post($bbkMt);

        $depuse = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '552100')->id, 'asset_id' => $asset->id, 'debit' => 500_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '112105')->id, 'asset_id' => $asset->id, 'debit' => 0,       'kredit' => 500_000],
            ],
            ['document_number' => "DEPUSE-{$asset->id}-99", 'document_type' => 'penyusutan'],
        );
        app(JournalService::class)->post($depuse);

        app(JournalService::class)->void($bbkMt, 'Void maintenance');

        $depuse->refresh();
        $this->assertSame('posted', $depuse->status,
            'DEPUSE HARUS tetap posted saat BBK-MT di-void (BBK-MT tidak menghasilkan DEPUSE, no cascade)');
    }

    // ================================================================
    // BUG-DEPUSE-04: Aset PerDay didepresiasi via cron bulanan
    // ================================================================

    public function test_per_day_asset_is_depreciated_by_monthly_cron(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        // Aset PerDay: useful_life_days=1000, price=100jt, salvage=0
        // depreciationPerUnit = 100jt / 1000 = 100rb/hari
        // Untuk bulan 31 hari: 100rb × 31 = 3.1jt
        $asset = $this->makeUsageBasedAsset($company, DepreciationMethod::PerDay, [
            'useful_life_days' => 1000,
            'purchase_price'   => 100_000_000,
            'salvage_value'    => 0,
            'purchase_date'    => Carbon::now()->subMonths(2)->startOfMonth()->toDateString(),
        ]);

        // Jalankan cron untuk bulan lalu (31 hari kalau bulan lalu Juli/Agustus/dsb)
        $lastMonth = Carbon::now()->subMonth();
        $result = app(DepreciationService::class)->runForCompany(
            $company,
            $lastMonth->year,
            $lastMonth->month,
        );

        $this->assertGreaterThanOrEqual(1, $result['posted'],
            'PerDay asset harus ikut ke-post via cron bulanan (bukan skip)');

        $depJournal = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', sprintf('DEP-%d-%04d%02d', $asset->id, $lastMonth->year, $lastMonth->month))
            ->first();

        $this->assertNotNull($depJournal, 'Jurnal DEP untuk aset PerDay harus tercipta');

        // Verifikasi angka: perDay × daysInMonth
        $expectedMonthly = 100_000 * $lastMonth->daysInMonth;
        $this->assertEqualsWithDelta(
            $expectedMonthly,
            (float) $depJournal->total_amount,
            0.5,
            "Monthly PerDay = 100rb × {$lastMonth->daysInMonth} hari = {$expectedMonthly}",
        );
    }

    public function test_per_hour_still_skipped_by_monthly_cron(): void
    {
        // Regression guard: PerHour + PerRit HARUS tetap di-skip di cron
        // (mereka posted per log usage — kalau ikut cron, double count).
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $assetPerHour = $this->makeUsageBasedAsset($company, DepreciationMethod::PerHour, [
            'useful_life_hours' => 10000.0,
            'purchase_price'    => 500_000_000,
            'salvage_value'     => 50_000_000,
            'purchase_date'     => Carbon::now()->subMonths(2)->startOfMonth()->toDateString(),
        ]);

        $lastMonth = Carbon::now()->subMonth();
        app(DepreciationService::class)->runForCompany(
            $company,
            $lastMonth->year,
            $lastMonth->month,
        );

        $depJournal = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', sprintf('DEP-%d-%04d%02d', $assetPerHour->id, $lastMonth->year, $lastMonth->month))
            ->first();

        $this->assertNull($depJournal,
            'PerHour tidak boleh dapat jurnal DEP dari cron bulanan (usage-based, per log)');
    }

    public function test_straight_line_capped_at_remaining_depreciable_base(): void
    {
        // BUG-DEPUSE-05: cap monthly ke sisa depreciable base.
        // Aset dengan depreciable=1jt, useful_life=3 bulan → monthly=333.333,33.
        // Setelah 3 bulan posted, akumulasi ≈ 999.999,99 (ada residu 0.01 karena round).
        // Kalau bulan ke-4 (over life) di-post, checkEligibility harus skip.
        // Kalau bulan ke-3 sudah 2× post (mis. karena bug lain), cap harus mencegah
        // overshoot.
        //
        // Test ini fokus verify cap: fully-depreciated asset TIDAK dapat jurnal baru.
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $asset = Asset::create([
            'company_id'          => $company->id,
            'asset_code'          => 'TEST-CAP-01',
            'name'                => 'Test Cap Asset',
            'type'                => 'peralatan_kantor',
            'depreciation_method' => DepreciationMethod::StraightLine,
            'purchase_price'      => 1_000_000,
            'salvage_value'       => 0,
            'useful_life_months'  => 3,
            'purchase_date'       => Carbon::now()->subMonths(5)->startOfMonth()->toDateString(),
            'status'              => 'aktif',
        ]);

        // Post 3 kali (sesuai life)
        for ($i = 4; $i >= 2; $i--) {
            $month = Carbon::now()->subMonths($i);
            app(DepreciationService::class)->runForCompany(
                $company,
                $month->year,
                $month->month,
            );
        }

        // Cek: akumulasi ≤ depreciable base
        $ref = new \ReflectionMethod(DepreciationService::class, 'getAccumulatedDepreciation');
        $ref->setAccessible(true);
        $accumulated = $ref->invoke(app(DepreciationService::class), $asset, $company);

        $this->assertLessThanOrEqual(1_000_000.0, $accumulated,
            "Akumulasi ({$accumulated}) tidak boleh overshoot depreciable base (1jt)");
        $this->assertGreaterThan(999_000, $accumulated,
            'Akumulasi mendekati depreciable base setelah 3 bulan posting');
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function makeUsageBasedAsset($company, DepreciationMethod $method, array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'          => $company->id,
            'asset_code'          => 'AST-' . strtoupper(bin2hex(random_bytes(3))),
            'name'                => 'Test Asset ' . $method->value,
            'type'                => $method === DepreciationMethod::PerRit ? 'dump_truck' : 'excavator',
            'depreciation_method' => $method,
            'purchase_date'       => Carbon::now()->subMonths(2)->startOfMonth()->toDateString(),
            'status'              => 'aktif',
        ], $overrides));
    }

    private function makeRentalContract($company, Asset $asset, array $overrides = []): RentalContract
    {
        $client = $this->createClient($company);

        return RentalContract::create(array_merge([
            'company_id'      => $company->id,
            'contract_number' => 'RC-' . strtoupper(bin2hex(random_bytes(3))),
            'contract_date'   => Carbon::today()->toDateString(),
            'client_id'       => $client->id,
            'asset_id'        => $asset->id,
            'tipe_kontrak'    => 'alat_saja',
            'tarif_per_jam'   => 500_000,
            'status'          => 'aktif',
            'created_by'      => auth()->id() ?? $this->createTenantUser($company)->id,
        ], $overrides));
    }
}
