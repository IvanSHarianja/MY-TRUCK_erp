<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\User;
use App\Services\Accounting\AssetCostPerUnitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 11 Hardening — regression tests untuk 4 fix:
 *   - HIGH-2: BIZ-05 filter periode pakai period_year/period_month (bukan entry_date)
 *             → jurnal adjustment tetap masuk laporan bulan yang benar
 *   - MEDIUM-2: edit RentalLog.asset_id trigger BBK void+repost dengan tag asset baru
 *
 * HIGH-1 (lockForUpdate race) — sudah covered oleh existing tests karena idempotent
 *   check + cap check tetap correct dalam single-thread test scenario. Race hanya
 *   observable di production concurrent — feature test PHPUnit tidak simulate itu.
 *
 * MEDIUM-1 (warning UI) — pure UI concern, tidak ada kondisi runtime yang bisa
 *   di-assert lewat feature test. Manual UI verification.
 */
class Sprint11HardeningTest extends TestCase
{
    private function makeAsset(Company $company, array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'          => $company->id,
            'asset_code'          => 'AST-' . strtoupper(Str::random(4)),
            'name'                => 'Test',
            'type'                => 'excavator',
            'purchase_date'       => '2024-01-15',
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_hours'   => null,
            'useful_life_months'  => 60,
            'status'              => 'aktif',
        ], $attributes));
    }

    private function makeAllInContract(Company $company, Asset $asset, Client $client, User $user): RentalContract
    {
        // All In supaya BBK generate cost (BBM + operator).
        return RentalContract::create([
            'company_id'             => $company->id,
            'contract_number'        => 'RC-' . strtoupper(Str::random(4)),
            'client_id'              => $client->id,
            'asset_id'               => $asset->id,
            'tipe_rental'            => 'all_in',
            'include_bbm'            => true,
            'include_operator'       => true,
            'tarif_per_jam'          => 350_000,
            'bbm_liter_per_jam'      => 10,
            'harga_bbm_per_liter'    => 7_000,
            'gaji_operator_per_hari' => 200_000,
            'uang_makan_per_hari'    => 50_000,
            'premi_per_jam'          => 5_000,
            'billed_jam'             => 0,
            'status'                 => 'aktif',
            'started_at'             => '2026-01-01',
            'created_by'             => $user->id,
        ]);
    }

    // ============================================================
    // HIGH-2: filter periode konsisten dengan IncomeStatement
    // ============================================================

    public function test_high2_adjustment_entry_masuk_ke_periode_yang_benar(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->makeAsset($company);

        // Jurnal adjustment: accountant post di 5 Sep, tapi untuk period Agustus.
        // Skenario akuntansi umum: koreksi period sebelumnya.
        $entry = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '551100')->id, 'asset_id' => $asset->id, 'debit' => 2_000_000, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '111100')->id, 'debit' => 0, 'kredit' => 2_000_000],
            ],
            [
                'status'       => 'posted',
                'posted_by'    => $user->id,
                'posted_at'    => now(),
                'total_amount' => 2_000_000,
                // Kunci: period_year+month = Agustus, entry_date = 5 September
                'period_year'  => 2026,
                'period_month' => 8,
            ],
            Carbon::create(2026, 9, 5), // entry_date = Sep 5
        );

        $this->assertNotNull($entry);

        // Cek: laporan Biaya per Unit untuk Agustus HARUS include jurnal ini
        $report = app(AssetCostPerUnitService::class)->getReport($company->id, 2026, 8);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertNotNull($row);
        $this->assertSame(2_000_000.0, $row['cost_total'],
            'Jurnal dengan period_month=8 (adjustment ber-entry_date Sep) HARUS masuk laporan Agustus'
        );

        // Sanity: laporan September TIDAK boleh include jurnal Agustus ini
        $reportSep = app(AssetCostPerUnitService::class)->getReport($company->id, 2026, 9);
        $rowSep = collect($reportSep['rows'])->firstWhere('asset_id', $asset->id);
        $this->assertSame(0.0, $rowSep['cost_total'],
            'Laporan Sep tidak boleh include jurnal yang period_month=8'
        );
    }

    // ============================================================
    // MEDIUM-2: edit RentalLog.asset_id → BBK void+repost tag baru
    // ============================================================

    public function test_medium2_edit_asset_id_rental_log_bbk_re_tag_ke_asset_baru(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $client  = $this->createClient($company);

        $assetA = $this->makeAsset($company, ['asset_code' => 'AST-A']);
        $assetB = $this->makeAsset($company, ['asset_code' => 'AST-B']);

        // Contract dengan asset A initially
        $contract = $this->makeAllInContract($company, $assetA, $client, $user);

        // Create rental log 10 jam → BBK terbentuk tag asset A
        $log = RentalLog::create([
            'company_id'         => $company->id,
            'rental_contract_id' => $contract->id,
            'asset_id'           => $assetA->id,
            'log_date'           => '2026-08-05',
            'hm_awal'            => 100,
            'hm_akhir'           => 110,
            'jam_kerja'          => 10,
            'created_by'         => $user->id,
        ]);

        // BBK original tag asset A
        $bbkOriginal = JournalEntry::withoutGlobalScopes()
            ->where('document_number', 'BBK-RL-' . $log->id)
            ->where('status', 'posted')
            ->with('lines')
            ->first();

        $this->assertNotNull($bbkOriginal, 'BBK original harus ada');
        $lineAssetIds = $bbkOriginal->lines->pluck('asset_id')->filter()->unique()->values()->all();
        $this->assertSame([$assetA->id], $lineAssetIds,
            'BBK original: cost lines harus tag asset A'
        );

        // Edit log: swap ke asset B (jam tetap 10)
        $log->update(['asset_id' => $assetB->id]);

        // BBK original → void
        $this->assertSame('void', $bbkOriginal->fresh()->status,
            'BBK original harus void setelah asset_id log berubah'
        );

        // BBK baru posted tag asset B — cari via journal_entry_id log
        $log->refresh();
        $bbkNew = JournalEntry::withoutGlobalScopes()
            ->where('id', $log->journal_entry_id)
            ->where('status', 'posted')
            ->with('lines')
            ->first();

        $this->assertNotNull($bbkNew, 'BBK baru harus ada setelah repost');
        $newLineAssetIds = $bbkNew->lines->pluck('asset_id')->filter()->unique()->values()->all();
        $this->assertSame([$assetB->id], $newLineAssetIds,
            'BBK baru: cost lines harus tag asset B (bukan A lagi)'
        );

        // Total amount BBK sama (jam tidak berubah)
        $this->assertSame(
            (float) $bbkOriginal->total_amount,
            (float) $bbkNew->total_amount,
            'BBK amount tidak berubah — jam sama, hanya tag yang berubah'
        );
    }

    public function test_medium2_edit_hanya_field_lain_tidak_trigger_bbk_repost(): void
    {
        // Kontrol test: kalau field yang tidak affect cost (mis. notes) berubah,
        // BBK tidak boleh void+repost — waste operation.
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $client  = $this->createClient($company);
        $asset   = $this->makeAsset($company);
        $contract = $this->makeAllInContract($company, $asset, $client, $user);

        $log = RentalLog::create([
            'company_id'         => $company->id,
            'rental_contract_id' => $contract->id,
            'asset_id'           => $asset->id,
            'log_date'           => '2026-08-05',
            'hm_awal'            => 100,
            'hm_akhir'            => 110,
            'jam_kerja'          => 10,
            'created_by'         => $user->id,
        ]);

        $log->refresh();
        $bbkOriginalId = $log->journal_entry_id;

        // Update field yang bukan cost field
        $log->update(['notes' => 'catatan tambahan']);
        $log->refresh();

        // BBK tetap sama, tidak di-repost
        $this->assertSame($bbkOriginalId, $log->journal_entry_id,
            'Update notes tidak boleh void+repost BBK'
        );

        $bbk = JournalEntry::withoutGlobalScopes()->find($bbkOriginalId);
        $this->assertSame('posted', $bbk->status);
    }
}
