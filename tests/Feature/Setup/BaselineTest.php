<?php

namespace Tests\Feature\Setup;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Material;
use Tests\TestCase;

/**
 * Smoke test untuk memastikan Sprint 0 baseline berfungsi:
 * - Migrasi jalan di sqlite in-memory
 * - Factory Company OK
 * - CompanyTemplateService seed 53 akun + 5 BU + 7 material
 * - Helper postableAccount() & businessUnit() bekerja
 * - Tenant terisolasi (Company A tidak lihat akun Company B via query eksplisit)
 */
class BaselineTest extends TestCase
{
    public function test_migrasi_dan_factory_dasar_jalan(): void
    {
        $company = $this->createTenant();

        $this->assertNotNull($company->id);
        $this->assertTrue($company->is_active);
    }

    public function test_company_template_seed_53_akun(): void
    {
        $company = $this->createTenant();

        $accountsCount = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->count();

        $this->assertSame(53, $accountsCount, 'CompanyTemplateService harus seed 53 akun');
    }

    public function test_company_template_seed_5_business_units(): void
    {
        $company = $this->createTenant();

        $codes = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        $this->assertSame(['ARMD', 'BONG', 'MATL', 'RENT', 'UMUM'], $codes);
    }

    public function test_company_template_seed_7_material_default(): void
    {
        $company = $this->createTenant();

        $count = Material::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->count();

        $this->assertSame(7, $count);
    }

    public function test_helper_postable_account_mengembalikan_akun_valid(): void
    {
        $company = $this->createTenant();

        $kas = $this->postableAccount($company, '111100');

        $this->assertSame('111100', $kas->code);
        $this->assertSame($company->id, $kas->company_id);
    }

    public function test_helper_business_unit_mengembalikan_bu_valid(): void
    {
        $company = $this->createTenant();

        $rent = $this->businessUnit($company, 'RENT');

        $this->assertSame('RENT', $rent->code);
        $this->assertSame($company->id, $rent->company_id);
    }

    public function test_dua_company_berbeda_tidak_saling_bocor_data(): void
    {
        $companyA = $this->createTenant(['name' => 'PT Alpha']);
        $companyB = $this->createTenant(['name' => 'PT Beta']);

        $accountsA = Account::withoutGlobalScopes()
            ->where('company_id', $companyA->id)
            ->pluck('id')
            ->all();

        $accountsB = Account::withoutGlobalScopes()
            ->where('company_id', $companyB->id)
            ->pluck('id')
            ->all();

        // Set berbeda, tidak ada ID yang tumpang tindih (walaupun code sama)
        $this->assertNotEmpty($accountsA);
        $this->assertNotEmpty($accountsB);
        $this->assertEmpty(array_intersect($accountsA, $accountsB));
    }

    public function test_acting_as_tenant_login_user(): void
    {
        $company = $this->createTenant();
        $user = $this->createTenantUser($company);

        $this->actingAsTenant($user, $company);

        $this->assertTrue(auth()->check());
        $this->assertSame($user->id, auth()->id());
    }
}
