<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Material;
use App\Services\Accounting\CashFlowLakService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use App\Services\Accounting\TrialBalanceService;
use App\Services\CompanyTemplateService;
use Tests\TestCase;

/**
 * Peran "Persediaan Material" (inventory_material).
 *
 * Sebelumnya persediaan material menumpang peran Persediaan Solar (Depo) —
 * akun persediaan solar & material tidak bisa dipisah tanpa saling rebut.
 * Pembelian (Dr) dan HPP (Cr) wajib resolve ke akun yang SAMA.
 */
class MaterialInventoryRoleTest extends TestCase
{
    public function test_peran_persediaan_material_terklasifikasi_benar(): void
    {
        $role = AccountRole::InventoryMaterial;

        $this->assertSame('Persediaan Material', $role->label());
        $this->assertSame('aset_lancar', $role->defaultSubCategory());
        $this->assertSame('operasi', $role->defaultCashFlow());
        $this->assertSame('aset', $role->categoryOf());
        $this->assertSame('inventory_material', AccountRole::standardCodeMapping()['111260']);

        $this->assertSame($role, AccountRole::suggestFromName('Persediaan Material Tanah Urug'));
        $this->assertSame(AccountRole::InventorySolar, AccountRole::suggestFromName('Persediaan Solar Depo'));
    }

    public function test_template_pt_baru_punya_akun_persediaan_material(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '111260')->first();

        $this->assertNotNull($account);
        $this->assertSame(AccountRole::InventoryMaterial, $account->role);
        $this->assertSame(
            app(CompanyTemplateService::class)->accountCount(),
            Account::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'Teks "COA N akun" di halaman daftar PT harus sama dengan jumlah akun yang dibuat',
        );
    }

    public function test_pembelian_dan_hpp_memakai_akun_persediaan_material(): void
    {
        $company = $this->tenantWithUser();

        $this->buyAndSell($company);

        $saldo = $this->saldo($company);
        $this->assertEquals(1_400_000, $saldo['111260'] ?? 0, 'Persediaan Material = beli 2jt − HPP 600rb');
        $this->assertEquals(0, $saldo['111220'] ?? 0, 'Persediaan Solar tidak boleh tersentuh transaksi material');
        $this->assertTrue(app(TrialBalanceService::class)->getGrandTotal($company->id, 2026, 12)['is_balanced']);
    }

    public function test_pt_lama_tanpa_akun_persediaan_material_tetap_memakai_111220(): void
    {
        $company = $this->tenantWithUser();
        // PT yang dibuat sebelum peran ini ada: tidak punya 111260.
        Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '111260')->delete();

        $this->buyAndSell($company);

        $saldo = $this->saldo($company);
        $this->assertEquals(1_400_000, $saldo['111220'] ?? 0, 'Perilaku lama dipertahankan sampai akun Persediaan Material dibuat');
    }

    public function test_akun_kode_sendiri_dengan_peran_persediaan_material_dipakai(): void
    {
        $company = $this->tenantWithUser();
        Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '111260')->update(['is_active' => false]);

        Account::create([
            'company_id'     => $company->id,
            'code'           => 'PM-01',
            'name'           => 'Stok Material Galian',
            'category'       => 'aset',
            'normal_balance' => 'debit',
            'role'           => AccountRole::InventoryMaterial->value,
            'is_active'      => true,
        ]);

        $this->buyAndSell($company);

        $this->assertEquals(1_400_000, $this->saldo($company)['PM-01'] ?? 0);
    }

    public function test_lak_mencatat_pembelian_material_tunai(): void
    {
        $company = $this->tenantWithUser();

        $this->buyAndSell($company);

        $lak = app(CashFlowLakService::class)->getReport($company->id, 2026, 12);
        $pembelian = collect($lak['pengeluaran']['pengeluaran_material']['items'])->firstWhere('label', 'PEMBELIAN MATERIAL');

        $this->assertEquals(2_000_000, $pembelian['amount']);
    }

    // ============================================================

    private function tenantWithUser(): Company
    {
        $company = $this->createTenant();
        $this->actingAs($this->createTenantUser($company));

        return $company;
    }

    /** Beli 10 @ 200rb tunai, jual 3 → HPP 600rb (MAC 200rb). */
    private function buyAndSell(Company $company): void
    {
        $material = Material::withoutGlobalScopes()->where('company_id', $company->id)->first();

        app(MaterialPurchaseService::class)->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 10, 'unit_price' => 200_000, 'payment_method' => 'tunai',
        ]);

        app(MaterialSaleService::class)->create([
            'material_id'  => $material->id,
            'client_id'    => $this->createClient($company)->id,
            'sale_date'    => '2026-08-05',
            'volume'       => 3,
            'harga_satuan' => 300_000,
            'metode'       => 'tunai',
        ]);
    }

    /** @return array<string, float> kode akun → saldo (sesuai saldo normal) */
    private function saldo(Company $company): array
    {
        return app(TrialBalanceService::class)->getBalances($company->id, 2026, 12)
            ->pluck('saldo', 'code')
            ->all();
    }
}
