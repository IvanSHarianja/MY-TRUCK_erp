<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Providers\AppServiceProvider;
use App\Services\CompanyTemplateService;
use Filament\Forms\Components\Select;
use Tests\TestCase;

/**
 * Regression: dropdown searchable Filament memotong daftar di 50 pilihan.
 *
 * COA standar 55 akun → 5 akun berkode terbesar (552600 ke atas, 959000)
 * hilang dari dropdown "Akun Induk" TANPA pesan error. User mengira akunnya
 * tidak ada. Batas dinaikkan terpusat di AppServiceProvider.
 */
class SelectOptionsLimitTest extends TestCase
{
    public function test_semua_dropdown_memakai_batas_terpusat(): void
    {
        // Dropdown baru otomatis ikut — tidak perlu diatur satu per satu.
        $this->assertSame(AppServiceProvider::SELECT_OPTIONS_LIMIT, Select::make('apa_saja')->getOptionsLimit());
        $this->assertGreaterThan(50, AppServiceProvider::SELECT_OPTIONS_LIMIT, 'Harus di atas bawaan Filament');
    }

    public function test_batas_khusus_per_komponen_tetap_menang(): void
    {
        $this->assertSame(5, Select::make('khusus')->optionsLimit(5)->getOptionsLimit());
    }

    public function test_batas_cukup_untuk_seluruh_akun_coa(): void
    {
        // Guard utama: kalau template COA tumbuh melewati batas, akun paling
        // belakang akan hilang lagi dari dropdown tanpa pesan apa pun.
        $this->assertGreaterThan(
            app(CompanyTemplateService::class)->accountCount(),
            AppServiceProvider::SELECT_OPTIONS_LIMIT,
            'Batas dropdown harus lebih besar dari jumlah akun COA standar',
        );
    }

    public function test_akun_berkode_terbesar_ikut_muncul_di_dropdown_akun_induk(): void
    {
        $company = $this->createTenant();
        $user = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $jumlahAkun = Account::withoutGlobalScopes()->where('company_id', $company->id)->count();
        $this->assertGreaterThan(50, $jumlahAkun, 'Prasyarat: COA harus melewati batas lama (50)');

        // Akun yang dulu terpotong karena kodenya paling besar.
        $this->assertNotNull(
            Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '959000')->first(),
        );
        $this->assertGreaterThanOrEqual(
            $jumlahAkun,
            AppServiceProvider::SELECT_OPTIONS_LIMIT,
            'Seluruh akun satu PT harus muat di dropdown Akun Induk',
        );
    }

    public function test_halaman_buat_akun_ter_render(): void
    {
        $company = $this->createTenant();
        $this->actingAs($this->createTenantUser($company));

        $this->get("/admin/{$company->slug}/accounts/create")->assertOk();
    }
}
