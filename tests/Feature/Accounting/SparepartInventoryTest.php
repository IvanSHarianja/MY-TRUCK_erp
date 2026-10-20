<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountRole;
use App\Enums\MaintenanceType;
use App\Enums\MaterialJenis;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\RelationManagers\MaintenanceLogsRelationManager;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialStockMovement;
use App\Services\Accounting\CashFlowLakService;
use App\Services\Accounting\MaintenanceService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use App\Services\Accounting\TrialBalanceService;
use App\Services\CompanyTemplateService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sparepart sebagai persediaan: dibeli → masuk stok, dipakai saat servis →
 * stok berkurang & jadi beban maintenance sebesar MAC.
 *
 * Dibeli dan dipakai HARUS lewat akun persediaan yang sama (111270), terpisah
 * dari Persediaan Material (111260).
 */
class SparepartInventoryTest extends TestCase
{
    private const TAHUN = 2026;
    private const BULAN = 12;

    // ============================================================
    // Peran & template
    // ============================================================

    public function test_peran_persediaan_sparepart_terklasifikasi_benar(): void
    {
        $role = AccountRole::InventorySparepart;

        $this->assertSame('Persediaan Sparepart', $role->label());
        $this->assertSame('aset_lancar', $role->defaultSubCategory());
        $this->assertSame('aset', $role->categoryOf());
        $this->assertSame('1. Aset Lancar', $role->group());
        $this->assertSame('inventory_sparepart', AccountRole::standardCodeMapping()['111270']);
        $this->assertSame($role, AccountRole::suggestFromName('Persediaan Sparepart Gudang'));
    }

    public function test_template_pt_baru_punya_akun_persediaan_sparepart(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('code', '111270')->first();

        $this->assertNotNull($account);
        $this->assertSame(AccountRole::InventorySparepart, $account->role);
        $this->assertSame(
            app(CompanyTemplateService::class)->accountCount(),
            Account::withoutGlobalScopes()->where('company_id', $company->id)->count(),
        );
    }

    public function test_material_lama_otomatis_berjenis_jual(): void
    {
        $company = $this->createTenant();

        $material = Material::withoutGlobalScopes()->where('company_id', $company->id)->first();

        $this->assertSame(MaterialJenis::Jual, $material->jenis, 'Material template harus tetap bisa dijual');
        $this->assertFalse($material->isSparepart());
    }

    // ============================================================
    // Pembelian → stok
    // ============================================================

    public function test_pembelian_sparepart_masuk_akun_persediaan_sparepart(): void
    {
        $company = $this->tenantWithUser();
        $sparepart = $this->makeSparepart($company);

        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);

        $saldo = $this->saldo($company);
        $this->assertEquals(1_500_000, $saldo['111270'] ?? 0, 'Sparepart masuk 111270');
        $this->assertEquals(0, $saldo['111260'] ?? 0, 'Persediaan Material tidak boleh tersentuh');

        $sparepart->refresh();
        $this->assertSame(10.0, (float) $sparepart->current_stock);
        $this->assertSame(150_000.0, (float) $sparepart->current_mac);
    }

    public function test_sparepart_tidak_bisa_dijual_ke_klien(): void
    {
        $company = $this->tenantWithUser();
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('berjenis Sparepart');

        app(MaterialSaleService::class)->create([
            'material_id'  => $sparepart->id,
            'client_id'    => $this->createClient($company)->id,
            'sale_date'    => '2026-12-05',
            'volume'       => 1,
            'harga_satuan' => 200_000,
            'metode'       => 'tunai',
        ]);
    }

    // ============================================================
    // Pemakaian saat servis
    // ============================================================

    public function test_servis_dengan_sparepart_mengurangi_stok_dan_menjurnal_tiga_baris(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);

        $log = app(MaintenanceService::class)->log([
            'asset_id'         => $asset->id,
            'maintenance_date' => '2026-12-10',
            'type'             => MaintenanceType::ServiceRutin->value,
            'description'      => 'Ganti filter oli',
            'cost'             => 200_000,     // jasa montir
            'items'            => [['material_id' => $sparepart->id, 'qty' => 2]],
        ]);

        // Stok berkurang, MAC tidak berubah
        $sparepart->refresh();
        $this->assertSame(8.0, (float) $sparepart->current_stock);
        $this->assertSame(150_000.0, (float) $sparepart->current_mac);

        // Biaya: jasa 200rb + sparepart 2 × 150rb = 500rb
        $this->assertSame(300_000.0, $log->sparepartCost());
        $this->assertSame(500_000.0, $log->totalCost());
        $this->assertSame(200_000, (int) $log->cost, 'Kolom cost tetap berarti biaya jasa saja');

        $journal = JournalEntry::withoutGlobalScopes()->with('lines')->find($log->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(500_000.0, (float) $journal->total_amount);

        $lines = $journal->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(500_000.0, (float) $lines['551400']->debit, 'Beban maintenance = jasa + sparepart');
        $this->assertSame(200_000.0, (float) $lines['111100']->kredit, 'Kas hanya sebesar jasa');
        $this->assertSame(300_000.0, (float) $lines['111270']->kredit, 'Persediaan Sparepart berkurang');
        $this->assertSame($asset->id, $lines['551400']->asset_id, 'Beban ditag ke unit untuk laporan Biaya per Unit');

        // Jejak stok
        $out = MaterialStockMovement::withoutGlobalScopes()
            ->where('material_id', $sparepart->id)->where('movement_type', 'out')->first();
        $this->assertNotNull($out);
        $this->assertSame(-2.0, (float) $out->qty_change);
        $this->assertSame(AssetMaintenanceLog::class, $out->source_type);
        $this->assertSame($log->id, (int) $out->source_id);

        $this->assertTrue(app(TrialBalanceService::class)->getGrandTotal($company->id, self::TAHUN, self::BULAN)['is_balanced']);
    }

    public function test_servis_tanpa_biaya_jasa_tetap_menjurnal_sparepart(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 5, harga: 100_000);

        $log = $this->servis($asset, $sparepart, qty: 1, cost: 0);

        $journal = JournalEntry::withoutGlobalScopes()->with('lines')->find($log->journal_entry_id);
        $this->assertNotNull($journal, 'Sparepart dari stok tetap biaya walau jasa gratis');
        $this->assertCount(2, $journal->lines, 'Tanpa biaya tunai, baris Kas tidak ditulis');
        $this->assertTrue($journal->isBalanced());
    }

    public function test_servis_tanpa_sparepart_perilaku_lama_tidak_berubah(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);

        $log = app(MaintenanceService::class)->log([
            'asset_id'         => $asset->id,
            'maintenance_date' => '2026-12-10',
            'type'             => MaintenanceType::ServiceRutin->value,
            'description'      => 'Servis ringan',
            'cost'             => 250_000,
        ]);

        $journal = JournalEntry::withoutGlobalScopes()->with('lines')->find($log->journal_entry_id);
        $this->assertCount(2, $journal->lines);
        $this->assertSame(250_000.0, (float) $journal->total_amount);
        $this->assertSame(250_000.0, $log->totalCost());
    }

    public function test_stok_sparepart_tidak_cukup_ditolak(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 1, harga: 100_000);

        try {
            $this->servis($asset, $sparepart, qty: 3, cost: 0);
            $this->fail('Pemakaian melebihi stok harus ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak cukup', $e->getMessage());
        }

        $sparepart->refresh();
        $this->assertSame(1.0, (float) $sparepart->current_stock, 'Stok tidak boleh berubah saat ditolak');
        $this->assertSame(0, AssetMaintenanceLog::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'Log tidak boleh tersimpan separuh jalan');
    }

    public function test_material_dagangan_ditolak_sebagai_sparepart_servis(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $materialJual = Material::withoutGlobalScopes()->where('company_id', $company->id)->first();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('bukan Sparepart');

        $this->servis($asset, $materialJual, qty: 1, cost: 0);
    }

    public function test_sparepart_pt_lain_ditolak(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepartLain = $this->makeSparepart($this->createTenant());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak ditemukan di PT ini');

        $this->servis($asset, $sparepartLain, qty: 1, cost: 0);
    }

    // ============================================================
    // Edit & hapus → stok kembali
    // ============================================================

    public function test_hapus_servis_mengembalikan_stok_dan_void_jurnal(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);
        $log = $this->servis($asset, $sparepart, qty: 2, cost: 100_000);
        $journalId = $log->journal_entry_id;

        $log->delete();

        $sparepart->refresh();
        $this->assertSame(10.0, (float) $sparepart->current_stock, 'Stok kembali utuh');
        $this->assertSame('void', JournalEntry::withoutGlobalScopes()->find($journalId)->status);

        // Persediaan kembali ke nilai pembelian; beban maintenance nol lagi
        $saldo = $this->saldo($company);
        $this->assertEquals(1_500_000, $saldo['111270'] ?? 0);
        $this->assertEquals(0, $saldo['551400'] ?? 0);
        $this->assertTrue(app(TrialBalanceService::class)->getGrandTotal($company->id, self::TAHUN, self::BULAN)['is_balanced']);
    }

    public function test_edit_servis_menyesuaikan_stok_dan_jurnal(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);
        $log = $this->servis($asset, $sparepart, qty: 2, cost: 100_000);

        // Ternyata yang dipakai 3, bukan 2; jasa naik jadi 150rb
        $log = app(MaintenanceService::class)->updateLog($log, [
            'maintenance_date' => '2026-12-10',
            'type'             => MaintenanceType::ServiceRutin->value,
            'description'      => 'Ganti filter oli (revisi)',
            'cost'             => 150_000,
            'items'            => [['material_id' => $sparepart->id, 'qty' => 3]],
        ]);

        $sparepart->refresh();
        $this->assertSame(7.0, (float) $sparepart->current_stock, '10 − 3, bukan 10 − 2 − 3');
        $this->assertSame(450_000.0, $log->sparepartCost());
        $this->assertSame(600_000.0, $log->totalCost());

        $journal = JournalEntry::withoutGlobalScopes()->with('lines')->find($log->journal_entry_id);
        $this->assertSame(600_000.0, (float) $journal->total_amount);
        $this->assertTrue($journal->isBalanced());

        // Hanya satu jurnal BBK-MT aktif untuk log ini
        $aktif = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', 'BBK-MT-' . $log->id)
            ->where('status', 'posted')
            ->count();
        $this->assertSame(1, $aktif);
        $this->assertTrue(app(TrialBalanceService::class)->getGrandTotal($company->id, self::TAHUN, self::BULAN)['is_balanced']);
    }

    public function test_edit_menghapus_semua_sparepart_mengembalikan_stok(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);
        $log = $this->servis($asset, $sparepart, qty: 4, cost: 0);

        $log = app(MaintenanceService::class)->updateLog($log, [
            'maintenance_date' => '2026-12-10',
            'type'             => MaintenanceType::ServiceRutin->value,
            'description'      => 'Ternyata tidak ganti sparepart',
            'cost'             => 50_000,
            'items'            => [],
        ]);

        $sparepart->refresh();
        $this->assertSame(10.0, (float) $sparepart->current_stock);
        $this->assertSame(0.0, $log->sparepartCost());
        $this->assertSame(50_000.0, $log->totalCost());
    }

    public function test_dua_sparepart_dalam_satu_servis_digabung_satu_baris_jurnal(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $filter = $this->makeSparepart($company, 'Filter Oli');
        $oli    = $this->makeSparepart($company, 'Oli Mesin');
        $this->beliSparepart($company, $filter, qty: 5, harga: 100_000);
        $this->beliSparepart($company, $oli, qty: 20, harga: 50_000);

        $log = app(MaintenanceService::class)->log([
            'asset_id'         => $asset->id,
            'maintenance_date' => '2026-12-10',
            'type'             => MaintenanceType::ServiceRutin->value,
            'description'      => 'Ganti oli + filter',
            'cost'             => 0,
            'items'            => [
                ['material_id' => $filter->id, 'qty' => 1],
                ['material_id' => $oli->id, 'qty' => 6],
            ],
        ]);

        $this->assertSame(400_000.0, $log->sparepartCost());   // 100rb + 300rb

        $journal = JournalEntry::withoutGlobalScopes()->with('lines')->find($log->journal_entry_id);
        $this->assertCount(2, $journal->lines, 'Dua sparepart ke akun yang sama → satu baris kredit');
        $this->assertTrue($journal->isBalanced());
    }

    public function test_akun_persediaan_sparepart_hilang_membatalkan_seluruh_transaksi(): void
    {
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);

        // Akun dinonaktifkan setelah stok ada
        Account::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('code', '111270')->update(['is_active' => false]);

        try {
            $this->servis($asset, $sparepart, qty: 2, cost: 0);
            $this->fail('Tanpa akun persediaan, servis harus ditolak — bukan stok berkurang diam-diam');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Persediaan Sparepart', $e->getMessage());
        }

        $sparepart->refresh();
        $this->assertSame(10.0, (float) $sparepart->current_stock, 'Stok tidak boleh berkurang');
    }

    public function test_lak_mencatat_pembelian_sparepart(): void
    {
        $company = $this->tenantWithUser();
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);

        $lak = app(CashFlowLakService::class)->getReport($company->id, self::TAHUN, self::BULAN);
        $baris = collect($lak['pengeluaran']['pengeluaran_kantor']['items'])
            ->firstWhere('label', 'PEMBELIAN SPAREPART');

        $this->assertEquals(1_500_000, $baris['amount']);
        $this->assertEquals($lak['saldoAkhir'], $lak['posisiKas']['total']['saldo_akhir'],
            'LAK harus rekonsiliasi dengan posisi kas');
    }

    public function test_catat_servis_lewat_form_memotong_stok(): void
    {
        // Test service tidak menyentuh kode form. Di sini alur penuh diuji lewat
        // komponen Filament: form → ->using() → MaintenanceService → stok & jurnal.
        $company = $this->tenantWithUser();
        $asset = $this->makeAsset($company);
        $sparepart = $this->makeSparepart($company);
        $this->beliSparepart($company, $sparepart, qty: 10, harga: 150_000);

        $this->get("/admin/{$company->slug}/assets/{$asset->id}/edit")->assertOk();

        Livewire::test(MaintenanceLogsRelationManager::class, [
            'ownerRecord' => $asset,
            'pageClass'   => EditAsset::class,
        ])
            ->assertSuccessful()
            ->assertSee('Total Biaya')
            ->mountAction(TestAction::make('create')->table())
            ->assertActionMounted(TestAction::make('create')->table())
            // Isi modal dirender di sisi klien — field dicek lewat skema, bukan HTML.
            ->assertFormFieldExists('items')
            ->assertFormFieldExists('cost')
            ->setActionData([
                'maintenance_date' => '2026-12-10',
                'type'             => MaintenanceType::ServiceRutin->value,
                'description'      => 'Ganti filter oli lewat form',
                'cost'             => 100_000,
                'items'            => [['material_id' => $sparepart->id, 'qty' => 2]],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $sparepart->refresh();
        $this->assertSame(8.0, (float) $sparepart->current_stock, 'Form harus meneruskan sparepart ke service');

        $log = AssetMaintenanceLog::withoutGlobalScopes()->where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame(400_000.0, $log->totalCost());
        $this->assertNotNull($log->journal_entry_id);
    }

    public function test_halaman_material_dan_penjualan_ter_render(): void
    {
        $company = $this->tenantWithUser();
        $this->makeSparepart($company);

        $this->get("/admin/{$company->slug}/materials")->assertOk()->assertSee('Jenis');
        $this->get("/admin/{$company->slug}/material-purchases")->assertOk();
        $this->get("/admin/{$company->slug}/material-sales/create")->assertOk();
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function tenantWithUser(): Company
    {
        $company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($company), $company);

        return $company;
    }

    private function makeSparepart(Company $company, string $name = 'Filter Oli'): Material
    {
        return Material::create([
            'company_id'       => $company->id,
            'code'             => 'SP-' . strtoupper(Str::random(5)),
            'name'             => $name,
            'jenis'            => MaterialJenis::Sparepart->value,
            'harga_per_satuan' => 0,
            'harga_pokok'      => 0,
            'satuan'           => 'pcs',
            'is_active'        => true,
        ]);
    }

    private function makeAsset(Company $company): Asset
    {
        return Asset::create([
            'company_id'          => $company->id,
            'asset_code'          => 'EX-' . strtoupper(Str::random(4)),
            'name'                => 'Excavator Test',
            'type'                => 'excavator',
            'purchase_date'       => '2024-01-15',
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'useful_life_months'  => 60,
            'status'              => 'aktif',
        ]);
    }

    private function beliSparepart(Company $company, Material $material, float $qty, int $harga): void
    {
        app(MaterialPurchaseService::class)->record([
            'material_id'    => $material->id,
            'purchase_date'  => '2026-12-01',
            'qty'            => $qty,
            'unit_price'     => $harga,
            'payment_method' => 'tunai',
        ]);
    }

    private function servis(Asset $asset, Material $material, float $qty, int $cost): AssetMaintenanceLog
    {
        return app(MaintenanceService::class)->log([
            'asset_id'         => $asset->id,
            'maintenance_date' => '2026-12-10',
            'type'             => MaintenanceType::ServiceRutin->value,
            'description'      => 'Servis + ganti sparepart',
            'cost'             => $cost,
            'items'            => [['material_id' => $material->id, 'qty' => $qty]],
        ]);
    }

    /** @return array<string, float> kode akun → saldo */
    private function saldo(Company $company): array
    {
        return app(TrialBalanceService::class)
            ->getBalances($company->id, self::TAHUN, self::BULAN)
            ->pluck('saldo', 'code')
            ->all();
    }
}
