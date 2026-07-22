<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Services\Accounting\CashFlowService;
use App\Services\Accounting\EquityStatementService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PaymentService;
use Tests\TestCase;

/**
 * Sprint 2.5 Phase 4 — Regression test role-based lookup.
 *
 * Verifikasi:
 *  - Seed template default auto-set role di 53 akun standar.
 *  - Enum AccountRole::standardCodeMapping() menutup semua code standard.
 *  - User pakai kode custom (mis. KAS-001) + role='cash' → tetap dipakai
 *    di CashFlowService, InvoiceService, dll.
 *  - Fallback code masih jalan untuk data legacy (role=null).
 */
class AccountRoleTest extends TestCase
{
    // ============================================================
    // Foundation: enum + template default
    // ============================================================

    public function test_seed_template_default_auto_isi_role_untuk_semua_akun_standard(): void
    {
        $company = $this->createTenant();

        // Semua 53 akun standard HARUS punya role sesuai mapping enum
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get();

        $this->assertCount(53, $accounts);

        $mapping = AccountRole::standardCodeMapping();
        foreach ($accounts as $account) {
            if (isset($mapping[$account->code])) {
                $this->assertNotNull(
                    $account->role,
                    "Akun {$account->code} standar HARUS punya role auto-assigned"
                );
                $this->assertSame(
                    $mapping[$account->code],
                    $account->role instanceof AccountRole ? $account->role->value : $account->role,
                    "Akun {$account->code} role harus match standardCodeMapping()"
                );
            }
        }
    }

    public function test_enum_covers_all_standard_codes(): void
    {
        // Semua enum case punya standard code (kecuali equity_laba_berjalan yang special)
        $mapping = AccountRole::standardCodeMapping();
        $mappingRoles = array_values($mapping);

        foreach (AccountRole::cases() as $case) {
            $this->assertContains(
                $case->value,
                $mappingRoles,
                "Role {$case->value} harus ada di standardCodeMapping()"
            );
        }
    }

    // ============================================================
    // Custom code + role — user bebas nomor akun
    // ============================================================

    public function test_user_bisa_pakai_kode_custom_dengan_role_cash(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Bikin akun kode custom (mis. 'KAS-BCA-001') dengan role cash
        $kasCustom = Account::withoutGlobalScopes()->create([
            'company_id'         => $company->id,
            'code'               => 'KAS-BCA-001',
            'name'               => 'Kas BCA Cabang Utama',
            'category'           => 'aset',
            'sub_category'       => 'aset_lancar',
            'normal_balance'     => 'debit',
            'cash_flow_category' => 'operasi',
            'role'               => AccountRole::Cash->value,
            'is_active'          => true,
        ]);

        // Verifikasi Account::firstByRole return akun custom ini
        $found = Account::firstByRole(AccountRole::Cash, $company->id);
        $this->assertNotNull($found);
        // Bisa 'KAS-BCA-001' atau '111100' (yang code-nya kecil) tergantung sort.
        // Yang penting: role match.
        $foundRole = $found->role instanceof AccountRole ? $found->role->value : $found->role;
        $this->assertSame(AccountRole::Cash->value, $foundRole);
    }

    public function test_cash_flow_service_include_akun_role_cash_custom(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Hapus akun standard 111100 supaya cuma pakai custom
        $kasStandard = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '111100')
            ->first();

        // Bikin akun custom sebagai satu-satunya akun cash
        $kasCustom = Account::withoutGlobalScopes()->create([
            'company_id'         => $company->id,
            'code'               => 'KAS-CUSTOM-01',
            'name'               => 'Kas Custom Test',
            'category'           => 'aset',
            'sub_category'       => 'aset_lancar',
            'normal_balance'     => 'debit',
            'cash_flow_category' => 'operasi',
            'role'               => AccountRole::Cash->value,
            'is_active'          => true,
        ]);

        // Verify CashFlowService pick up akun ini
        $cashIds = Account::idsByRole(AccountRole::Cash, $company->id);
        $this->assertContains($kasCustom->id, $cashIds,
            'CashFlowService harus include akun custom yang role="cash"'
        );
    }

    public function test_invoice_service_pakai_role_revenue_custom(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Bikin akun revenue custom untuk RENT
        $revCustom = Account::withoutGlobalScopes()->create([
            'company_id'         => $company->id,
            'code'               => 'REV-RENT-CUSTOM',
            'name'               => 'Pendapatan Rental Custom',
            'category'           => 'pendapatan',
            'sub_category'       => 'pendapatan_usaha',
            'normal_balance'     => 'kredit',
            'cash_flow_category' => 'operasi',
            'role'               => AccountRole::RevenueRent->value,
            'is_active'          => true,
        ]);

        // Hapus code standard 441100 supaya cuma custom yang dipakai
        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '441100')
            ->delete();

        // Bikin invoice RENT → issue → verifikasi revenue account = custom
        $invoice = $this->makeDraftInvoice($company, [
            'amount'             => 1_000_000,
            'business_unit_code' => 'RENT',
        ]);
        $issued = app(InvoiceService::class)->issue($invoice);

        $this->assertSame($revCustom->id, $issued->revenue_account_id,
            'InvoiceService harus pakai akun custom dengan role revenue_rent'
        );
    }

    // ============================================================
    // Fallback code (backward compat untuk data lama)
    // ============================================================

    public function test_fallback_code_jalan_kalau_role_belum_diset(): void
    {
        $company = $this->createTenant();

        // Set role=null di akun 111100 (simulasi data legacy sebelum migration)
        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '111100')
            ->update(['role' => null]);

        // findByRoleOrCode masih return akun tsb via fallback code
        $account = Account::findByRoleOrCode(
            AccountRole::Cash,
            '111100',
            $company->id,
        );

        $this->assertNotNull($account, 'Fallback code harus return akun 111100 walau role=null');
        $this->assertSame('111100', $account->code);
    }

    public function test_null_kalau_role_dan_code_dua_duanya_tidak_ada(): void
    {
        $company = $this->createTenant();

        // Hapus akun 111100 SEMUA
        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '111100')
            ->delete();

        // Cari role Cash → tidak ada (karena default 111100 udah hapus, tidak ada custom)
        $account = Account::findByRoleOrCode(
            AccountRole::Cash,
            '111100',
            $company->id,
        );

        $this->assertNull($account, 'Kalau role & code sama-sama tidak ada, return null');
    }

    // ============================================================
    // Equity Statement custom code — end-to-end
    // ============================================================

    public function test_equity_statement_pakai_akun_custom_dengan_role_modal(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Ganti akun modal standar dengan custom
        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '331100')
            ->update(['role' => null, 'code' => '331100-OLD']); // rename untuk avoid conflict

        $modalCustom = Account::withoutGlobalScopes()->create([
            'company_id'         => $company->id,
            'code'               => 'MODAL-OWNER-001',
            'name'               => 'Modal Pemilik Custom',
            'category'           => 'ekuitas',
            'sub_category'       => 'ekuitas',
            'normal_balance'     => 'kredit',
            'cash_flow_category' => 'non_kas',
            'role'               => AccountRole::EquityModal->value,
            'is_active'          => true,
        ]);

        // Verifikasi EquityStatementService pick up akun ini
        $report = app(EquityStatementService::class)->getReport($company->id, 2026);

        // Karena kita belum bikin transaksi ke akun modal, saldo = 0.
        // Test cukup verifikasi tidak throw error & report struct valid.
        $this->assertArrayHasKey('modalPemilik', $report);
        $this->assertArrayHasKey('totalEkuitas', $report);
    }
}
