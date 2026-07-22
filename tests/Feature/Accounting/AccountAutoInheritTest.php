<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use Tests\TestCase;

/**
 * Auto-Inherit properties dari role/category saat save Account.
 *
 * Test skenario:
 *  - User isi role → sub_category & cash_flow_category auto-derived dari role.
 *  - User isi category tanpa role → sub_category & cash_flow_category default per category.
 *  - User explicit isi sub_category → dihormati (tidak overwrite).
 *  - Backfill migration jalan untuk data legacy.
 */
class AccountAutoInheritTest extends TestCase
{
    // ============================================================
    // Priority 1: role → derive persis
    // ============================================================

    public function test_role_cash_auto_isi_sub_lancar_dan_arus_kas_operasi(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'KAS-TEST',
            'name'           => 'Kas Test',
            'category'       => 'aset',
            'normal_balance' => 'debit',
            'role'           => AccountRole::Cash->value,
            // sengaja tidak isi sub_category & cash_flow_category
        ]);

        $this->assertSame('aset_lancar', $account->sub_category);
        $this->assertSame('operasi', $account->cash_flow_category);
    }

    public function test_role_fixed_asset_armada_auto_isi_sub_tetap_dan_arus_investasi(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'ARMADA-TEST',
            'name'           => 'Aset Armada Test',
            'category'       => 'aset',
            'normal_balance' => 'debit',
            'role'           => AccountRole::FixedAssetArmada->value,
        ]);

        $this->assertSame('aset_tetap', $account->sub_category);
        $this->assertSame('investasi', $account->cash_flow_category);
    }

    public function test_role_equity_modal_auto_isi_sub_ekuitas_dan_arus_pendanaan(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'MODAL-TEST',
            'name'           => 'Modal Test',
            'category'       => 'ekuitas',
            'normal_balance' => 'kredit',
            'role'           => AccountRole::EquityModal->value,
        ]);

        $this->assertSame('ekuitas', $account->sub_category);
        $this->assertSame('pendanaan', $account->cash_flow_category);
    }

    public function test_role_opex_penyusutan_auto_isi_sub_operasional_dan_arus_non_kas(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'PENYUSUTAN-TEST',
            'name'           => 'Beban Penyusutan Test',
            'category'       => 'beban',
            'normal_balance' => 'debit',
            'role'           => AccountRole::OpexPenyusutan->value,
        ]);

        $this->assertSame('beban_operasional', $account->sub_category);
        $this->assertSame('non_kas', $account->cash_flow_category);
    }

    // ============================================================
    // Priority 2: category → default per kategori
    // ============================================================

    public function test_category_aset_tanpa_role_default_lancar_operasi(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'ASET-TEST',
            'name'           => 'Aset Generik',
            'category'       => 'aset',
            'normal_balance' => 'debit',
            // no role, no sub_category
        ]);

        $this->assertSame('aset_lancar', $account->sub_category);
        $this->assertSame('operasi', $account->cash_flow_category);
    }

    public function test_category_ekuitas_tanpa_role_default_ekuitas_pendanaan(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'EKUITAS-GENERIK',
            'name'           => 'Ekuitas Generik',
            'category'       => 'ekuitas',
            'normal_balance' => 'kredit',
        ]);

        $this->assertSame('ekuitas', $account->sub_category);
        $this->assertSame('pendanaan', $account->cash_flow_category);
    }

    public function test_category_kewajiban_tanpa_role_default_lancar_operasi(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'UTANG-TEST',
            'name'           => 'Utang Generik',
            'category'       => 'kewajiban',
            'normal_balance' => 'kredit',
        ]);

        $this->assertSame('kewajiban_lancar', $account->sub_category);
        $this->assertSame('operasi', $account->cash_flow_category);
    }

    public function test_category_beban_tanpa_role_default_operasional_operasi(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'BEBAN-GEN',
            'name'           => 'Beban Generik',
            'category'       => 'beban',
            'normal_balance' => 'debit',
        ]);

        $this->assertSame('beban_operasional', $account->sub_category);
        $this->assertSame('operasi', $account->cash_flow_category);
    }

    // ============================================================
    // Override manual dihormati
    // ============================================================

    public function test_user_explicit_isi_sub_category_tidak_di_overwrite(): void
    {
        $company = $this->createTenant();

        // User explicit isi 'aset_tetap' meskipun tidak set role FixedAsset
        $account = Account::withoutGlobalScopes()->create([
            'company_id'     => $company->id,
            'code'           => 'MANUAL-TEST',
            'name'           => 'Manual Override Test',
            'category'       => 'aset',
            'sub_category'   => 'aset_tetap',   // ← explicit
            'normal_balance' => 'debit',
        ]);

        $this->assertSame('aset_tetap', $account->sub_category,
            'User explicit set sub_category harus dihormati, tidak di-overwrite'
        );
    }

    public function test_user_explicit_cash_flow_tidak_di_overwrite(): void
    {
        $company = $this->createTenant();

        $account = Account::withoutGlobalScopes()->create([
            'company_id'         => $company->id,
            'code'               => 'INVESTASI-TEST',
            'name'               => 'Manual Cash Flow',
            'category'           => 'aset',
            'cash_flow_category' => 'investasi',   // ← explicit
            'normal_balance'     => 'debit',
        ]);

        $this->assertSame('investasi', $account->cash_flow_category);
    }

    // ============================================================
    // AccountRole enum default methods
    // ============================================================

    public function test_semua_role_return_default_sub_category_valid(): void
    {
        $validSubCategories = [
            'aset_lancar', 'aset_tetap',
            'kewajiban_lancar', 'kewajiban_panjang',
            'ekuitas',
            'pendapatan_usaha', 'pendapatan_lain',
            'beban_hpp', 'beban_operasional',
            'penutup',
        ];

        foreach (AccountRole::cases() as $role) {
            $this->assertContains(
                $role->defaultSubCategory(),
                $validSubCategories,
                "Role {$role->value} return sub_category invalid: {$role->defaultSubCategory()}"
            );
        }
    }

    public function test_semua_role_return_default_cash_flow_valid(): void
    {
        $validCashFlows = ['operasi', 'investasi', 'pendanaan', 'non_kas'];

        foreach (AccountRole::cases() as $role) {
            $this->assertContains(
                $role->defaultCashFlow(),
                $validCashFlows,
                "Role {$role->value} return cash_flow invalid: {$role->defaultCashFlow()}"
            );
        }
    }
}
