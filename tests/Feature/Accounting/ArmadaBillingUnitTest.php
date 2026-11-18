<?php

namespace Tests\Feature\Accounting;

use App\Filament\Resources\ArmadaContracts\Pages\EditArmadaContract;
use App\Filament\Resources\ArmadaContracts\Pages\ListArmadaContracts;
use App\Filament\Resources\ArmadaContracts\RelationManagers\MobilizationsRelationManager;
use App\Filament\Resources\ArmadaContracts\RelationManagers\RitLogsRelationManager;
use App\Models\ArmadaContract;
use App\Models\ArmadaMobilization;
use App\Models\Asset;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\ArmadaContractService;
use App\Services\Accounting\InvoiceService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kontrak Armada: satuan tarif per rit / per M³ / per hari + Mobilisasi Alat Berat
 * (mobilisasi alat berat) yang ditagih gabung dalam satu invoice.
 */
class ArmadaBillingUnitTest extends TestCase
{
    private Company $company;
    private User $user;
    private Asset $truk;
    private Asset $selfLoader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->user    = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->user, $this->company);
        Filament::setCurrentPanel('admin');

        $this->truk       = $this->asset('DT-01', 'dump_truck');
        $this->selfLoader = $this->asset('SL-01', 'kendaraan_operasional');
    }

    private function asset(string $code, string $type): Asset
    {
        return Asset::create([
            'company_id' => $this->company->id, 'asset_code' => $code, 'name' => $code, 'type' => $type,
            'purchase_date' => '2024-01-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0,
            'useful_life_months' => 96, 'status' => 'aktif',
        ]);
    }

    private function contract(string $satuan, int $tarif): ArmadaContract
    {
        return ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA26-' . random_int(1000, 9999),
            'client_id' => $this->createClient($this->company)->id, 'tipe_kontrak' => 'alat_saja',
            'route_description' => 'Kuari → Proyek', 'satuan_tarif' => $satuan, 'tarif_per_rit' => $tarif,
            'billed_rit' => 0, 'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->user->id,
        ]);
    }

    private function log(ArmadaContract $c, array $attrs): RitLog
    {
        return RitLog::create(array_merge([
            'company_id' => $this->company->id, 'armada_contract_id' => $c->id, 'asset_id' => $this->truk->id,
            'log_date' => now()->toDateString(), 'rit_count' => 1, 'created_by' => $this->user->id,
        ], $attrs));
    }

    private function gendong(ArmadaContract $c, int $amount, ?int $assetId = null, string $alat = 'Excavator PC 75'): ArmadaMobilization
    {
        return ArmadaMobilization::create([
            'company_id' => $this->company->id, 'armada_contract_id' => $c->id, 'tanggal' => now()->toDateString(),
            'jenis' => 'mobilisasi', 'asset_id' => $assetId ?? $this->selfLoader->id, 'alat_diangkut' => $alat,
            'amount' => $amount, 'created_by' => $this->user->id,
        ]);
    }

    private function tagih(ArmadaContract $c): \App\Models\Invoice
    {
        return app(ArmadaContractService::class)->tagih($c->fresh());
    }

    public function test_kontrak_lama_default_per_rit_tidak_berubah(): void
    {
        $c = $this->contract('per_rit', 250_000);
        $this->log($c, ['rit_count' => 4]);

        $inv = $this->tagih($c);

        $this->assertEquals(1_000_000, $inv->amount);
        $this->assertStringContainsString('Angkutan 4 rit @ Rp 250.000', $inv->description);
        $this->assertSame(4, $c->fresh()->billed_rit);
    }

    public function test_per_m3_menagih_volume_bukan_rit(): void
    {
        $c = $this->contract('per_m3', 45_000);
        $this->log($c, ['rit_count' => 3, 'volume_m3' => 18.5]);
        $this->log($c, ['rit_count' => 2, 'volume_m3' => 12]);

        $this->assertSame('30,5 M³', $c->fresh()->unbilledLabel());
        $inv = $this->tagih($c);

        $this->assertEquals(30.5 * 45_000, $inv->amount);
        $this->assertStringContainsString('30,5 M³ @ Rp 45.000', $inv->description);
        $this->assertSame(5, $c->fresh()->billed_rit, 'counter rit tetap menghitung rit');
    }

    public function test_per_hari_menagih_hari_kerja_termasuk_setengah_hari_dan_standby(): void
    {
        $c = $this->contract('per_hari', 1_500_000);
        $this->log($c, ['rit_count' => 0, 'hari_kerja' => 1]);      // standby
        $this->log($c, ['rit_count' => 6, 'hari_kerja' => 0.5]);

        $inv = $this->tagih($c);

        $this->assertEquals(2_250_000, $inv->amount);
        $this->assertStringContainsString('Sewa 1,5 hari', $inv->description);
        $this->assertSame(0, RitLog::whereNull('invoice_id')->count(), 'log standby (0 rit) ikut tertagih');
    }

    public function test_ongkos_gendong_saja_dan_pendapatan_ditag_ke_self_loader(): void
    {
        $c = $this->contract('per_rit', 250_000);
        $m = $this->gendong($c, 2_500_000);

        $inv = $this->tagih($c);

        $this->assertEquals(2_500_000, $inv->amount);
        $this->assertStringContainsString('Mobilisasi alat: Mobilisasi Excavator PC 75', $inv->description);
        $this->assertSame($inv->id, $m->fresh()->invoice_id);
        $this->assertSame($this->selfLoader->id, app(InvoiceService::class)->resolveRevenueAssetId($inv));
    }

    public function test_campuran_pemakaian_dan_gendong_jadi_satu_invoice(): void
    {
        $c = $this->contract('per_m3', 45_000);
        $this->log($c, ['rit_count' => 2, 'volume_m3' => 10]);
        $this->gendong($c, 2_000_000, $this->truk->id);
        $this->gendong($c, 1_500_000, $this->truk->id, 'Dozer D31');

        $this->assertSame('10 M³ + 2 mobilisasi alat', $c->fresh()->unbilledLabel());
        $inv = $this->tagih($c);

        $this->assertEquals(450_000 + 3_500_000, $inv->amount);
        $this->assertSame(1, \App\Models\Invoice::count());
        $this->assertSame($this->truk->id, app(InvoiceService::class)->resolveRevenueAssetId($inv), 'semua dari satu truk → ditag');
        $this->assertFalse($c->fresh()->hasUnbilled());

        $revenue = JournalEntry::withoutGlobalScopes()->with('lines')->find($inv->journal_entry_id)->lines->firstWhere('kredit', '>', 0);
        $this->assertEquals(3_950_000, $revenue->kredit);
    }

    public function test_void_invoice_melepas_log_dan_gendong_untuk_ditagih_ulang(): void
    {
        $c = $this->contract('per_hari', 1_000_000);
        $this->log($c, ['rit_count' => 0, 'hari_kerja' => 1]);
        $this->gendong($c, 2_000_000);
        $inv = $this->tagih($c);

        app(InvoiceService::class)->void($inv, 'salah tarif');

        $c = $c->fresh();
        $this->assertSame('1 hari + 1 mobilisasi alat', $c->unbilledLabel());
        $this->assertEquals(3_000_000, $this->tagih($c)->amount);
    }

    public function test_tutup_kontrak_diblokir_bila_gendong_belum_ditagih(): void
    {
        $c = $this->contract('per_rit', 250_000);
        $this->gendong($c, 2_000_000);

        try {
            app(ArmadaContractService::class)->selesai($c->fresh());
            $this->fail('Seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1 mobilisasi alat', collect($e->errors())->flatten()->first());
        }
    }

    public function test_satuan_terkunci_setelah_ada_log(): void
    {
        $c = $this->contract('per_rit', 250_000);
        $this->log($c, ['rit_count' => 1]);

        $this->expectException(ValidationException::class);
        $c->fresh()->update(['satuan_tarif' => 'per_m3']);
    }

    public function test_tidak_ada_yang_ditagih_ditolak(): void
    {
        $c = $this->contract('per_m3', 45_000);

        $this->expectException(ValidationException::class);
        $this->tagih($c);
    }

    public function test_daftar_kontrak_memuat_angka_belum_ditagih_tanpa_query_per_baris(): void
    {
        $a = $this->contract('per_m3', 45_000);
        $this->log($a, ['rit_count' => 1, 'volume_m3' => 7]);
        $b = $this->contract('per_hari', 1_000_000);
        $this->gendong($b, 500_000);

        $loaded = ArmadaContract::query()->withUnbilled()->orderBy('id')->get();

        DB::enableQueryLog();
        $labels = $loaded->map->unbilledLabel()->all();
        $values = $loaded->map(fn ($c) => $c->unbilledSummary()['total'])->all();
        $this->assertSame([], DB::getQueryLog(), 'angka belum ditagih tidak query ulang');
        DB::disableQueryLog();

        $this->assertSame(['7 M³', '1 mobilisasi alat'], $labels);
        $this->assertEquals([315_000, 500_000], $values);
    }

    public function test_form_log_per_m3_wajib_volume(): void
    {
        $c = $this->contract('per_m3', 45_000);

        Livewire::test(RitLogsRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditArmadaContract::class])
            ->mountAction(TestAction::make('create')->table())
            ->setActionData(['log_date' => now()->toDateString(), 'asset_id' => $this->truk->id, 'rit_count' => 2, 'volume_m3' => null])
            ->callMountedAction()
            ->assertHasActionErrors(['volume_m3' => 'required']);

        $this->assertSame(0, RitLog::count());
    }

    public function test_tab_ongkos_gendong_mencatat_baris(): void
    {
        $c = $this->contract('per_rit', 250_000);

        Livewire::test(MobilizationsRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditArmadaContract::class])
            ->mountAction(TestAction::make('create')->table())
            ->setActionData([
                'tanggal' => now()->toDateString(), 'jenis' => 'mobilisasi', 'alat_diangkut' => 'Excavator PC 200',
                'asset_id' => $this->selfLoader->id, 'amount' => '3000000',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $m = ArmadaMobilization::firstOrFail();
        $this->assertSame([$this->company->id, 3_000_000, null], [$m->company_id, $m->amount, $m->invoice_id]);
    }

    public function test_halaman_daftar_kontrak_tampil(): void
    {
        $c = $this->contract('per_m3', 45_000);
        $this->log($c, ['rit_count' => 1, 'volume_m3' => 7]);

        Livewire::test(ListArmadaContracts::class)
            ->assertOk()
            ->assertSee('per M³')
            ->assertSee('7 M³');
    }
}
