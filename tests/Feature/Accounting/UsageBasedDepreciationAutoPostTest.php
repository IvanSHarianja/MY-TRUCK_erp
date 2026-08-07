<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BIZ-03 — Feature test Auto-post Penyusutan saat log usage di-post.
 *
 * Cakupan:
 *  - RentalLog created (asset per_hour) → DEPUSE-{asset}-{log_id} posted
 *  - RitLog created (asset per_rit) → DEPUSE posted
 *  - Amount = usage × depreciationPerUnit, capped ke sisa depreciable base
 *  - Straight-line asset → SKIP (tidak double-count dengan cron bulanan)
 *  - PerDay method → SKIP with log info (menunggu Q5)
 *  - Idempotent via document_number
 *  - Edit jam_kerja / rit_count → void lama, post baru
 *  - Edit asset_id → void jurnal lama (asset lama), post baru (asset baru)
 *  - Delete log → void DEPUSE
 *  - Periode closed → skip (guard existing dari JournalService)
 */
class UsageBasedDepreciationAutoPostTest extends TestCase
{
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
            'useful_life_hours'   => 10_000, // per_unit = 54.000/jam
            'status'              => 'aktif',
        ], $attributes));
    }

    /**
     * Rental contract "alat saja" (tidak include BBM/operator) supaya
     * OperationalCostService return total=0 → BBK skip. Isolasi test ke DEPUSE.
     */
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
            'log_date'           => '2026-08-05',
            'hm_awal'            => 100,
            'hm_akhir'           => 110,
            'jam_kerja'          => 10,
            'created_by'         => $user->id,
        ], $attributes));
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
            'route_description'  => 'Test route Jakarta - Bandung',
            'tarif_per_rit'      => 300_000,
            'billed_rit'         => 0,
            'status'             => 'aktif',
            'started_at'         => '2026-01-01',
            'created_by'         => $creator,
        ]);
    }

    private function findDepuse(Company $company, Asset $asset, int $logId): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', sprintf('DEPUSE-%d-%d', $asset->id, $logId))
            ->first();
    }

    // ============================================================
    // RentalLog + asset per_hour → DEPUSE posted
    // ============================================================

    public function test_rental_log_created_post_depuse_untuk_per_hour_asset(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->createAsset($company); // per_hour, per_unit = 54rb/jam
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);

        $log = $this->makeRentalLog($company, $contract, $user, ['jam_kerja' => 10]);

        $depuse = $this->findDepuse($company, $asset, $log->id);

        $this->assertNotNull($depuse, 'DEPUSE harus otomatis post saat RentalLog dibuat');
        $this->assertSame('posted', $depuse->status);
        $this->assertSame('penyusutan', $depuse->document_type);

        // Amount = 10 jam × 54.000/jam = 540.000
        $this->assertSame(540_000.0, (float) $depuse->total_amount);
        $this->assertTrue($depuse->isBalanced());
        $this->assertCount(2, $depuse->lines);

        // Kedua line tag asset_id
        foreach ($depuse->lines as $line) {
            $this->assertSame($asset->id, $line->asset_id, 'DEPUSE line harus tag asset_id');
        }
    }

    public function test_rental_log_created_skip_depuse_untuk_straight_line_asset(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_hours'   => null,
            'useful_life_months'  => 60,
        ]);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);
        $log      = $this->makeRentalLog($company, $contract, $user);

        $this->assertNull($this->findDepuse($company, $asset, $log->id),
            'Straight-line asset tidak boleh dapat DEPUSE — mencegah double-count dengan cron bulanan'
        );
    }

    public function test_per_day_method_hanya_log_info_belum_post(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerDay->value,
            'useful_life_hours'   => null,
            'useful_life_days'    => 1825,
        ]);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);
        $log      = $this->makeRentalLog($company, $contract, $user);

        $this->assertNull($this->findDepuse($company, $asset, $log->id),
            'PerDay method belum di-wire — harus skip dengan log info, bukan post salah'
        );
    }

    // ============================================================
    // Idempotency + Edit + Delete
    // ============================================================

    public function test_edit_jam_kerja_void_lama_post_baru(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->createAsset($company);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);
        $log      = $this->makeRentalLog($company, $contract, $user, ['jam_kerja' => 10]);

        // Edit jam kerja: 10 → 15 jam
        $log->update(['jam_kerja' => 15, 'hm_akhir' => 115]);

        // Jurnal DEPUSE untuk log ini masih SATU yang posted (bukan dua),
        // amount = 15 × 54.000 = 810.000
        $posted = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', 'like', 'DEPUSE-%-' . $log->id)
            ->where('status', 'posted')
            ->get();

        $this->assertCount(1, $posted, 'Setelah edit: hanya 1 DEPUSE posted (lama voided)');
        $this->assertSame(810_000.0, (float) $posted->first()->total_amount);

        // Yang lama status void + ada pembalik
        $voided = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', 'like', 'DEPUSE-%-' . $log->id)
            ->where('status', 'void')
            ->count();
        $this->assertSame(1, $voided);
    }

    public function test_edit_asset_id_void_lama_post_baru_untuk_asset_baru(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $assetA = $this->createAsset($company, ['asset_code' => 'A', 'useful_life_hours' => 10_000]); // 54rb/jam
        $assetB = $this->createAsset($company, ['asset_code' => 'B', 'useful_life_hours' => 5_000]);  // 108rb/jam
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $assetA, $client);
        $log      = $this->makeRentalLog($company, $contract, $user, ['jam_kerja' => 10]);

        // Cek DEPUSE untuk asset A ada
        $depA = $this->findDepuse($company, $assetA, $log->id);
        $this->assertNotNull($depA);
        $this->assertSame(540_000.0, (float) $depA->total_amount);

        // Ganti asset_id ke B (tanpa ubah jam)
        $log->update(['asset_id' => $assetB->id]);

        // DEPUSE untuk asset A harus void
        $this->assertSame('void', $depA->fresh()->status);

        // DEPUSE baru untuk asset B ter-post
        $depB = $this->findDepuse($company, $assetB, $log->id);
        $this->assertNotNull($depB);
        $this->assertSame('posted', $depB->status);
        $this->assertSame(1_080_000.0, (float) $depB->total_amount, '10 jam × 108rb (assetB per_unit)');
    }

    public function test_delete_log_void_depuse(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->createAsset($company);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);
        $log      = $this->makeRentalLog($company, $contract, $user);

        $depuse = $this->findDepuse($company, $asset, $log->id);
        $this->assertSame('posted', $depuse->status);

        $log->delete();

        $this->assertSame('void', $depuse->fresh()->status);
    }

    // ============================================================
    // Cap ke sisa depreciable base
    // ============================================================

    public function test_depuse_amount_capped_ke_sisa_depreciable_base(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Aset kecil: 10jt beli, 0 residu, 100 jam umur → 100rb/jam.
        // Kalau log 200 jam, raw amount = 20jt, tapi depreciable base hanya 10jt.
        $asset = $this->createAsset($company, [
            'purchase_price'    => 10_000_000,
            'salvage_value'     => 0,
            'useful_life_hours' => 100,
        ]);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);
        $log      = $this->makeRentalLog($company, $contract, $user, ['jam_kerja' => 200]);

        $depuse = $this->findDepuse($company, $asset, $log->id);

        $this->assertNotNull($depuse);
        $this->assertSame(10_000_000.0, (float) $depuse->total_amount,
            'Amount harus di-cap ke sisa depreciable base (10jt), bukan 20jt raw'
        );
    }

    // ============================================================
    // RitLog + asset per_rit
    // ============================================================

    public function test_rit_log_created_post_depuse_untuk_per_rit_asset(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Aset per_rit: 300jt - 30jt = 270jt / 5000 rit = 54.000/rit
        $asset = $this->createAsset($company, [
            'type'                => 'dump_truck',
            'depreciation_method' => DepreciationMethod::PerRit->value,
            'useful_life_hours'   => null,
            'useful_life_rits'    => 5000,
            'purchase_price'      => 300_000_000,
            'salvage_value'       => 30_000_000,
        ]);
        $client   = $this->createClient($company);
        $contract = $this->makeArmadaContract($company, $client);

        $log = RitLog::create([
            'company_id'         => $company->id,
            'armada_contract_id' => $contract->id,
            'asset_id'           => $asset->id,
            'log_date'           => '2026-08-05',
            'rit_count'          => 12,
            'created_by'         => $user->id,
        ]);

        $depuse = $this->findDepuse($company, $asset, $log->id);

        $this->assertNotNull($depuse);
        // 12 rit × 54.000 = 648.000
        $this->assertSame(648_000.0, (float) $depuse->total_amount);
        foreach ($depuse->lines as $line) {
            $this->assertSame($asset->id, $line->asset_id);
        }
    }

    public function test_rit_log_skip_untuk_asset_per_hour(): void
    {
        // Cross-verify: RitLog TIDAK boleh post DEPUSE kalau aset per_hour.
        // (per_hour hanya dipicu dari RentalLog, bukan RitLog.)
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset = $this->createAsset($company, [
            'depreciation_method' => DepreciationMethod::PerHour->value,
            'useful_life_hours'   => 10_000,
        ]);
        $client   = $this->createClient($company);
        $contract = $this->makeArmadaContract($company, $client);

        $log = RitLog::create([
            'company_id'         => $company->id,
            'armada_contract_id' => $contract->id,
            'asset_id'           => $asset->id,
            'log_date'           => '2026-08-05',
            'rit_count'          => 10,
            'created_by'         => $user->id,
        ]);

        $this->assertNull($this->findDepuse($company, $asset, $log->id),
            'Aset per_hour tidak boleh dapat DEPUSE dari RitLog — beda channel'
        );
    }

    // ============================================================
    // Konsistensi laporan penyusutan setelah DEPUSE
    // ============================================================

    public function test_akumulasi_di_report_bertambah_setelah_depuse(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $asset    = $this->createAsset($company);
        $client   = $this->createClient($company);
        $contract = $this->makeAlatSajaContract($company, $asset, $client);

        // Sebelum ada log — akumulasi 0
        $report = app(\App\Services\Accounting\AssetDepreciationReportService::class)
            ->getReport($company->id, 2026, 8);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);
        $this->assertSame(0.0, $row['akumulasi']);

        // Post log 10 jam
        $log = $this->makeRentalLog($company, $contract, $user, ['jam_kerja' => 10]);

        // Setelah log, akumulasi = 540.000
        $report = app(\App\Services\Accounting\AssetDepreciationReportService::class)
            ->getReport($company->id, 2026, 8);
        $row = collect($report['rows'])->firstWhere('asset_id', $asset->id);

        $this->assertSame(540_000.0, $row['akumulasi']);
        $this->assertSame(599_460_000.0, $row['nilai_buku'], '600jt - 540rb');
    }
}
