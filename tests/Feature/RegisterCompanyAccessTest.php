<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Models\User;
use Tests\TestCase;

/**
 * Verifikasi canView() RegisterCompany — restriksi tombol "Daftarkan PT Baru":
 *   - User 0 tenant → BOLEH (first-time onboarding wajib)
 *   - User punya PT sebagai Owner → BOLEH
 *   - User Admin/Accountant/Viewer only → TIDAK BOLEH
 *   - Kombinasi mixed role → BOLEH kalau owner di minimal 1 PT
 */
class RegisterCompanyAccessTest extends TestCase
{
    public function test_user_tanpa_pt_boleh_register_first_time(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertTrue(RegisterCompany::canView(),
            'User 0 tenant WAJIB bisa register — first-time onboarding'
        );
    }

    public function test_owner_di_pt_boleh_register_pt_lain(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company, [], Role::Owner->value);
        $this->actingAs($owner);

        $this->assertTrue(RegisterCompany::canView(),
            'Owner boleh register PT tambahan'
        );
    }

    public function test_admin_saja_tidak_boleh_register(): void
    {
        $company = $this->createTenant();
        $admin   = $this->createTenantUser($company, [], Role::Admin->value);
        $this->actingAs($admin);

        $this->assertFalse(RegisterCompany::canView(),
            'Admin tanpa owner-role tidak boleh register PT baru'
        );
    }

    public function test_accountant_tidak_boleh_register(): void
    {
        $company    = $this->createTenant();
        $accountant = $this->createTenantUser($company, [], Role::Accountant->value);
        $this->actingAs($accountant);

        $this->assertFalse(RegisterCompany::canView());
    }

    public function test_viewer_tidak_boleh_register(): void
    {
        $company = $this->createTenant();
        $viewer  = $this->createTenantUser($company, [], Role::Viewer->value);
        $this->actingAs($viewer);

        $this->assertFalse(RegisterCompany::canView());
    }

    public function test_user_owner_di_pt_A_admin_di_pt_B_tetap_boleh_register(): void
    {
        // Owner minimal di 1 PT sudah cukup.
        $ownerCompany = $this->createTenant(['name' => 'PT Own']);
        $adminCompany = $this->createTenant(['name' => 'PT Adm']);
        $user         = User::factory()->create();

        $ownerCompany->users()->attach($user->id, ['role' => Role::Owner->value, 'is_active' => true]);
        $adminCompany->users()->attach($user->id, ['role' => Role::Admin->value, 'is_active' => true]);

        $this->actingAs($user);
        $this->assertTrue(RegisterCompany::canView(),
            'Owner minimal di 1 PT sudah cukup untuk register PT baru'
        );
    }

    public function test_pivot_inactive_tidak_dianggap_owner(): void
    {
        $company = $this->createTenant();
        $user    = User::factory()->create();
        // Owner tapi is_active = false — misal akses sudah dicabut
        $company->users()->attach($user->id, ['role' => Role::Owner->value, 'is_active' => false]);

        $this->actingAs($user);

        // Karena inactive, treated seolah 0 aktif → jatuh ke first-time logic
        $this->assertTrue(RegisterCompany::canView(),
            'User dengan semua pivot inactive fall-through ke first-time (0 aktif) → boleh register PT pertama yang aktif'
        );
    }

    public function test_guest_tidak_boleh_register(): void
    {
        $this->assertFalse(RegisterCompany::canView(),
            'Guest (belum login) tidak boleh akses register'
        );
    }
}
