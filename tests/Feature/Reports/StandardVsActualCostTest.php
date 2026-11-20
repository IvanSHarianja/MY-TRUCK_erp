<?php

namespace Tests\Feature\Reports;

use App\Enums\QuickTransactionType;
use App\Filament\Pages\Reports\StandardVsActualCost;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\IncomeStatementByAssetService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\QuickTransactionService;
use App\Services\Accounting\StandardVsActualCostService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Keputusan 2026-11-20: Laba Rugi memakai biaya aktual yang dibayar keuangan,
 * tarif biaya kontrak hanya standar pembanding.
 */
class StandardVsActualCostTest extends TestCase
{
    private Company $company;
    private User $owner;
    private Asset $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');

        $this->truck = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-01', 'name' => 'Dump Truck Hino', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 400_000_000, 'salvage_value' => 0,
            'useful_life_months' => 96, 'status' => 'aktif',
        ]);
    }

    /** Kontrak all-in: 20 L/rit × Rp10.000, uang jalan Rp150.000/rit, gaji Rp200.000/hari. */
    private function ritLog(string $date, int $rit): RitLog
    {
        $contract = ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA-' . random_int(1000, 9999),
            'client_id' => $this->createClient($this->company)->id, 'tipe_kontrak' => 'all_in',
            'bbm_liter_per_rit' => 20, 'harga_bbm_per_liter' => 10_000, 'uang_jalan_per_rit' => 150_000,
            'gaji_supir_per_hari' => 200_000, 'route_description' => 'Quarry - Proyek', 'tarif_per_rit' => 1_000_000,
            'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->owner->id,
        ]);

        return RitLog::create([
            'company_id' => $this->company->id, 'armada_contract_id' => $contract->id, 'asset_id' => $this->truck->id,
            'log_date' => $date, 'rit_count' => $rit, 'created_by' => $this->owner->id,
        ]);
    }

    private function actualCost(string $code, float $amount, ?int $assetId, string $date, ?string $docNo = null): void
    {
        $armd = BusinessUnit::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'ARMD')->value('id');
        app(JournalService::class)->post($this->makeJournalEntry($this->company, [
            ['account_id' => $this->postableAccount($this->company, $code)->id, 'asset_id' => $assetId, 'debit' => $amount, 'kredit' => 0],
            ['account_id' => $this->postableAccount($this->company, '111100')->id, 'debit' => 0, 'kredit' => $amount],
        ], array_filter(['business_unit_id' => $armd, 'document_number' => $docNo, 'document_type' => $docNo ? 'bkk' : null]), Carbon::parse($date)));
    }

    public function test_log_ritase_tidak_masuk_laba_rugi_biaya_aktual_masuk(): void
    {
        $this->ritLog('2026-10-10', 4);

        $pnl = collect(app(IncomeStatementByAssetService::class)->getReport($this->company->id, 2026, 10)['assets'])
            ->firstWhere('asset_id', $this->truck->id);
        $this->assertSame(0.0, $pnl['hpp'] + $pnl['beban_op'], 'Standar kontrak tidak masuk Laba Rugi');

        $this->actualCost('551100', 900_000, $this->truck->id, '2026-10-10');   // solar aktual
        $this->actualCost('551200', 650_000, $this->truck->id, '2026-10-11');   // uang jalan aktual

        $pnl = collect(app(IncomeStatementByAssetService::class)->getReport($this->company->id, 2026, 10)['assets'])
            ->firstWhere('asset_id', $this->truck->id);
        $this->assertSame(1_550_000.0, $pnl['hpp'], 'Laba Rugi per Unit memakai biaya aktual');
    }

    public function test_laporan_standar_vs_aktual(): void
    {
        $this->ritLog('2026-10-10', 4);  // standar: BBM 800rb, gaji 200rb, uang jalan 600rb
        $this->actualCost('551100', 900_000, $this->truck->id, '2026-10-10');
        $this->actualCost('552200', 200_000, $this->truck->id, '2026-10-31');
        $this->actualCost('551200', 650_000, $this->truck->id, '2026-10-12');
        $this->actualCost('551100', 300_000, null, '2026-10-15');                  // belum ditandai unit
        $this->actualCost('551100', 800_000, $this->truck->id, '2026-10-10', 'BBK-RT-999'); // jurnal lama
        $this->actualCost('551100', 500_000, $this->truck->id, '2026-11-01');      // bulan lain

        $r = app(StandardVsActualCostService::class)->build($this->company->id, 2026, 10);
        $row = $r['rows'][0];

        $this->assertSame('DT-01', $row['asset_code']);
        $this->assertEquals(['bbm' => 800_000, 'gaji' => 200_000, 'uang_jalan' => 600_000], $row['standar']);
        $this->assertEquals(['bbm' => 900_000, 'gaji' => 200_000, 'uang_jalan' => 650_000], $row['aktual']);
        $this->assertEquals(150_000, $row['selisih']);
        $this->assertEquals(4, $row['pemakaian']['rit']);
        $this->assertEquals(300_000, $r['untagged']);
        $this->assertSame(1, $r['legacy']['count']);
        $this->assertEquals(800_000, $r['legacy']['total']);

        Livewire::test(StandardVsActualCost::class)
            ->set('data.year', 2026)->set('data.month', 10)
            ->assertSee('DT-01')
            ->assertSee('jurnal biaya standar lama')
            ->assertSee('belum ditandai unit');
    }

    public function test_transaksi_unit_ditolak_untuk_jenis_non_unit_dan_unit_tenant_lain(): void
    {
        $svc = app(QuickTransactionService::class);
        $kas = $this->postableAccount($this->company, '111100');
        $umum = BusinessUnit::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'UMUM')->value('id');
        $armd = BusinessUnit::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'ARMD')->value('id');

        try {
            $svc->post($this->company, QuickTransactionType::BebanKantor, $kas, 100_000, businessUnitId: $umum, method: 'kas', assetId: $this->truck->id);
            $this->fail('Beban kantor tidak boleh ditandai unit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('asset_id', $e->errors());
        }

        $other = $this->createTenant();
        $foreign = Asset::create([
            'company_id' => $other->id, 'asset_code' => 'X-1', 'name' => 'Lain', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 1, 'salvage_value' => 0, 'useful_life_months' => 12, 'status' => 'aktif',
        ]);
        try {
            $svc->post($this->company, QuickTransactionType::BebanUangJalan, $kas, 100_000, businessUnitId: $armd, method: 'kas', assetId: $foreign->id);
            $this->fail('Unit tenant lain harus ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('asset_id', $e->errors());
        }

        $this->assertTrue(QuickTransactionType::BebanUangJalan->isBebanHpp());
        $this->assertTrue(QuickTransactionType::BebanUangJalan->allowsAsset());
    }
}
