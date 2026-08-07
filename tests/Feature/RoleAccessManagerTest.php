<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CompanyRolePermission;
use App\Models\User;
use App\Support\RoleAccessManager;
use App\Support\RoleMatrix;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Verifikasi per-tenant dynamic role permission — override DB menang atas
 * default RoleMatrix. Owner immutable.
 */
class RoleAccessManagerTest extends TestCase
{
    private RoleAccessManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(RoleAccessManager::class);
    }

    // ============================================================
    // Fallback ke default RoleMatrix
    // ============================================================

    public function test_kalau_no_override_pakai_default_role_matrix(): void
    {
        $company = $this->createTenant();

        // Default: Accountant TIDAK bisa void invoice
        $this->assertFalse($this->manager->has($company, Role::Accountant, Permission::InvoiceVoid));

        // Default: Admin BISA void invoice
        $this->assertTrue($this->manager->has($company, Role::Admin, Permission::InvoiceVoid));

        // Default: Viewer HANYA reports
        $this->assertTrue($this->manager->has($company, Role::Viewer, Permission::ReportsView));
        $this->assertFalse($this->manager->has($company, Role::Viewer, Permission::InvoiceCreate));
    }

    // ============================================================
    // Override menang atas default
    // ============================================================

    public function test_override_grant_permission_yang_default_tidak_ada(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company);

        // Awal: Accountant tidak boleh void
        $this->assertFalse($this->manager->has($company, Role::Accountant, Permission::InvoiceVoid));

        // Owner grant void ke Accountant (kebijakan PT ini)
        $this->manager->set($company, Role::Accountant, Permission::InvoiceVoid, true, $owner);

        // Sekarang Accountant BOLEH void di PT ini
        $this->assertTrue($this->manager->has($company, Role::Accountant, Permission::InvoiceVoid));

        // Default RoleMatrix tetap tidak berubah — override cuma per tenant
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::InvoiceVoid));
    }

    public function test_override_revoke_permission_yang_default_ada(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company);

        // Awal: Admin bisa void
        $this->assertTrue($this->manager->has($company, Role::Admin, Permission::InvoiceVoid));

        // Owner cabut void dari Admin
        $this->manager->set($company, Role::Admin, Permission::InvoiceVoid, false, $owner);

        $this->assertFalse($this->manager->has($company, Role::Admin, Permission::InvoiceVoid));
    }

    public function test_override_isolated_per_tenant(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();
        $owner    = $this->createTenantUser($companyA);

        // Owner PT A cabut void dari Admin di PT A
        $this->manager->set($companyA, Role::Admin, Permission::InvoiceVoid, false, $owner);

        // PT A: admin TIDAK bisa void
        $this->assertFalse($this->manager->has($companyA, Role::Admin, Permission::InvoiceVoid));

        // PT B: admin TETAP bisa void (default, tidak ada override)
        $this->assertTrue($this->manager->has($companyB, Role::Admin, Permission::InvoiceVoid));
    }

    // ============================================================
    // Owner immutable
    // ============================================================

    public function test_owner_selalu_semua_permission_walau_ada_row_di_db(): void
    {
        $company = $this->createTenant();

        // Manipulasi database langsung: coba disable UserManage untuk Owner
        CompanyRolePermission::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'role'       => 'owner',
            'permission' => Permission::UserManage->value,
            'is_granted' => false,
        ]);

        // Manager tetap return true untuk Owner (safety immutable)
        $this->assertTrue($this->manager->has($company, Role::Owner, Permission::UserManage));

        // Semua permission untuk owner tetap true
        foreach (Permission::cases() as $perm) {
            $this->assertTrue(
                $this->manager->has($company, Role::Owner, $perm),
                "Owner harus tetap punya {$perm->value} walau ada row DB manipulatif",
            );
        }
    }

    public function test_set_owner_permission_throw_exception(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permission Owner immutable');

        $this->manager->set($company, Role::Owner, Permission::UserManage, false, $owner);
    }

    // ============================================================
    // Reset ke default
    // ============================================================

    public function test_reset_menghapus_semua_override_untuk_pt(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company);

        $this->manager->set($company, Role::Accountant, Permission::InvoiceVoid, true, $owner);
        $this->manager->set($company, Role::Viewer, Permission::InvoiceCreate, true, $owner);

        $this->assertSame(2, CompanyRolePermission::withoutGlobalScopes()
            ->where('company_id', $company->id)->count());

        $deleted = $this->manager->reset($company);
        $this->assertSame(2, $deleted);

        // Setelah reset, kembali ke default
        $this->assertFalse($this->manager->has($company, Role::Accountant, Permission::InvoiceVoid));
        $this->assertFalse($this->manager->has($company, Role::Viewer, Permission::InvoiceCreate));
    }

    public function test_reset_per_role_hanya_hapus_row_role_itu(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company);

        $this->manager->set($company, Role::Accountant, Permission::InvoiceVoid, true, $owner);
        $this->manager->set($company, Role::Viewer, Permission::InvoiceCreate, true, $owner);

        // Reset hanya Accountant
        $this->manager->reset($company, Role::Accountant);

        // Accountant kembali default (false)
        $this->assertFalse($this->manager->has($company, Role::Accountant, Permission::InvoiceVoid));
        // Viewer override tetap ada
        $this->assertTrue($this->manager->has($company, Role::Viewer, Permission::InvoiceCreate));
    }

    // ============================================================
    // Integrasi User::canIn — pakai RoleAccessManager
    // ============================================================

    public function test_user_can_in_ikut_override_bukan_default_static(): void
    {
        $company    = $this->createTenant();
        $owner      = $this->createTenantUser($company, [], Role::Owner->value);
        $accountant = $this->createTenantUser($company, [], Role::Accountant->value);

        // Default: accountant tidak boleh void
        $this->assertFalse($accountant->canIn($company, Permission::InvoiceVoid));

        // Owner grant void
        $this->manager->set($company, Role::Accountant, Permission::InvoiceVoid, true, $owner);

        // Refresh manager (per-request memoization ke-reset otomatis via set)
        $this->assertTrue($accountant->canIn($company, Permission::InvoiceVoid));
    }

    public function test_owner_role_manage_hanya_owner_bukan_admin(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company, [], Role::Owner->value);
        $admin   = $this->createTenantUser($company, [], Role::Admin->value);

        $this->assertTrue($owner->canIn($company, Permission::RoleManage),
            'Owner harus punya RoleManage'
        );
        $this->assertFalse($admin->canIn($company, Permission::RoleManage),
            'Admin TIDAK boleh RoleManage — hanya Owner yang bisa kelola matrix'
        );
    }

    // ============================================================
    // Matrix snapshot untuk UI
    // ============================================================

    public function test_matrix_for_reflect_override(): void
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company);

        $this->manager->set($company, Role::Viewer, Permission::InvoiceCreate, true, $owner);

        $matrix = $this->manager->matrixFor($company);
        $row    = collect($matrix)->firstWhere('permission', Permission::InvoiceCreate);

        $this->assertTrue($row['allowed_by_role']['viewer'],
            'Matrix snapshot harus reflect override (viewer boleh InvoiceCreate setelah grant)'
        );
        // Owner column selalu true
        $this->assertTrue($row['allowed_by_role']['owner']);
    }
}
