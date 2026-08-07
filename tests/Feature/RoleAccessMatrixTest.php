<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Support\RoleMatrix;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifikasi Role & Permission system:
 *   1. RoleMatrix konsisten dengan expectation matrix per role
 *   2. User::canIn/canCurrent bekerja dengan Filament tenant context
 *   3. Laravel Policies mengaplikasikan matrix ke Resource
 *
 * Ini test yang menjaga matrix tidak "silent drift" — kalau ada developer
 * ubah RoleMatrix::permissionsFor tanpa aware, test yang gagal akan
 * menandai perubahan itu.
 */
class RoleAccessMatrixTest extends TestCase
{
    // ============================================================
    // Matrix expectations — sanity
    // ============================================================

    public function test_owner_punya_semua_permission(): void
    {
        foreach (Permission::cases() as $perm) {
            $this->assertTrue(
                RoleMatrix::has(Role::Owner, $perm),
                "Owner harus punya {$perm->value}",
            );
        }
    }

    public function test_admin_boleh_manage_dan_void_tapi_tidak_governance(): void
    {
        // Admin BOLEH:
        $this->assertTrue(RoleMatrix::has(Role::Admin, Permission::UserManage));
        $this->assertTrue(RoleMatrix::has(Role::Admin, Permission::InvoiceVoid));
        $this->assertTrue(RoleMatrix::has(Role::Admin, Permission::JournalVoid));
        $this->assertTrue(RoleMatrix::has(Role::Admin, Permission::MasterDataManage));

        // Admin TIDAK BOLEH:
        $this->assertFalse(RoleMatrix::has(Role::Admin, Permission::CompanyDelete));
        $this->assertFalse(RoleMatrix::has(Role::Admin, Permission::PeriodClose));
    }

    public function test_accountant_input_tapi_tidak_void_tidak_manage_master(): void
    {
        // Accountant BOLEH:
        $this->assertTrue(RoleMatrix::has(Role::Accountant, Permission::InvoiceCreate));
        $this->assertTrue(RoleMatrix::has(Role::Accountant, Permission::JournalPost));
        $this->assertTrue(RoleMatrix::has(Role::Accountant, Permission::PaymentManage));
        $this->assertTrue(RoleMatrix::has(Role::Accountant, Permission::OperationalManage));
        $this->assertTrue(RoleMatrix::has(Role::Accountant, Permission::QuickTransaction));

        // Accountant TIDAK BOLEH:
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::InvoiceVoid));
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::JournalVoid));
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::MasterDataManage));
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::UserManage));
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::PeriodClose));
    }

    public function test_viewer_hanya_boleh_reports(): void
    {
        // Viewer BOLEH:
        $this->assertTrue(RoleMatrix::has(Role::Viewer, Permission::ReportsView));
        $this->assertTrue(RoleMatrix::has(Role::Viewer, Permission::ReportsExportPdf));

        // Viewer TIDAK BOLEH apapun yang lain:
        $forbidden = [
            Permission::UserManage,
            Permission::CompanyDelete,
            Permission::PeriodClose,
            Permission::ActivityLogView,
            Permission::ChartOfAccountsManage,
            Permission::MasterDataManage,
            Permission::OperationalManage,
            Permission::InvoiceCreate,
            Permission::InvoiceVoid,
            Permission::PaymentManage,
            Permission::JournalPost,
            Permission::JournalVoid,
            Permission::QuickTransaction,
            Permission::DepreciationRun,
        ];
        foreach ($forbidden as $perm) {
            $this->assertFalse(
                RoleMatrix::has(Role::Viewer, $perm),
                "Viewer TIDAK boleh {$perm->value}",
            );
        }
    }

    // ============================================================
    // User helpers × Filament tenant context
    // ============================================================

    private function setupUserAtRole(Role $role): array
    {
        $company = $this->createTenant();
        $user    = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true]);

        $this->actingAs($user);
        Filament::setTenant($company, isQuiet: true);

        return [$company, $user];
    }

    public function test_user_role_in_return_role_yang_benar(): void
    {
        [$company, $owner] = $this->setupUserAtRole(Role::Owner);
        $this->assertSame(Role::Owner, $owner->roleIn($company));

        [$companyB, $viewer] = $this->setupUserAtRole(Role::Viewer);
        $this->assertSame(Role::Viewer, $viewer->roleIn($companyB));
    }

    public function test_user_pivot_inactive_return_null_role(): void
    {
        $company = $this->createTenant();
        $user    = User::factory()->create();
        $company->users()->attach($user->id, ['role' => Role::Admin->value, 'is_active' => false]);

        $this->assertNull($user->roleIn($company),
            'User dengan pivot is_active=false tidak boleh dianggap punya role'
        );
    }

    public function test_can_in_konsisten_dengan_matrix(): void
    {
        [$company, $accountant] = $this->setupUserAtRole(Role::Accountant);

        $this->assertTrue($accountant->canIn($company, Permission::InvoiceCreate));
        $this->assertTrue($accountant->canIn($company, Permission::JournalPost));
        $this->assertFalse($accountant->canIn($company, Permission::InvoiceVoid));
        $this->assertFalse($accountant->canIn($company, Permission::UserManage));
    }

    public function test_can_current_pakai_tenant_filament_aktif(): void
    {
        [$company, $admin] = $this->setupUserAtRole(Role::Admin);

        $this->assertTrue($admin->canCurrent(Permission::InvoiceCreate));
        $this->assertTrue($admin->canCurrent(Permission::InvoiceVoid));
        $this->assertFalse($admin->canCurrent(Permission::PeriodClose));
    }

    // ============================================================
    // Policy integration via Laravel Gate ($user->can())
    // ============================================================

    public function test_policy_invoice_owner_boleh_semua_admin_boleh_void_accountant_tidak(): void
    {
        // Owner
        [$company, $owner] = $this->setupUserAtRole(Role::Owner);
        $client = Client::create(['company_id' => $company->id, 'code' => 'X', 'name' => 'X']);
        $invoice = new Invoice(['company_id' => $company->id, 'status' => 'terbit']);
        $invoice->exists = true;

        $this->assertTrue($owner->can('create', Invoice::class));
        $this->assertTrue($owner->can('void', $invoice));

        // Admin
        [$companyA, $admin] = $this->setupUserAtRole(Role::Admin);
        $invoiceA = new Invoice(['company_id' => $companyA->id, 'status' => 'terbit']);
        $invoiceA->exists = true;

        $this->assertTrue($admin->can('create', Invoice::class));
        $this->assertTrue($admin->can('void', $invoiceA));

        // Accountant — boleh create tapi TIDAK void
        [$companyAc, $accountant] = $this->setupUserAtRole(Role::Accountant);
        $invoiceAc = new Invoice(['company_id' => $companyAc->id, 'status' => 'terbit']);
        $invoiceAc->exists = true;

        $this->assertTrue($accountant->can('create', Invoice::class));
        $this->assertFalse($accountant->can('void', $invoiceAc),
            'Accountant tidak boleh void invoice'
        );

        // Viewer — tidak boleh apapun kecuali view
        [$companyV, $viewer] = $this->setupUserAtRole(Role::Viewer);
        $invoiceV = new Invoice(['company_id' => $companyV->id, 'status' => 'terbit']);
        $invoiceV->exists = true;

        $this->assertFalse($viewer->can('create', Invoice::class));
        $this->assertFalse($viewer->can('void', $invoiceV));
        $this->assertTrue($viewer->can('viewAny', Invoice::class),
            'Viewer boleh lihat list invoice'
        );
    }

    public function test_policy_journal_entry_void_hanya_owner_admin(): void
    {
        [$company, $accountant] = $this->setupUserAtRole(Role::Accountant);
        $journal = new JournalEntry(['company_id' => $company->id, 'status' => 'posted']);
        $journal->exists = true;

        $this->assertTrue($accountant->can('create', JournalEntry::class));
        $this->assertTrue($accountant->can('post', $journal));
        $this->assertFalse($accountant->can('void', $journal));
    }

    public function test_policy_asset_master_data_hanya_owner_admin(): void
    {
        [$company, $accountant] = $this->setupUserAtRole(Role::Accountant);

        $this->assertTrue($accountant->can('viewAny', Asset::class),
            'Accountant boleh lihat aset (untuk keperluan input log)'
        );
        $this->assertFalse($accountant->can('create', Asset::class),
            'Accountant tidak boleh create aset — master data hanya admin/owner'
        );

        [$companyV, $viewer] = $this->setupUserAtRole(Role::Viewer);
        $this->assertTrue($viewer->can('viewAny', Asset::class),
            'Viewer boleh lihat aset (referensi)'
        );
        $this->assertFalse($viewer->can('create', Asset::class));
    }

    public function test_policy_payment_semua_role_write_kecuali_viewer(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            [$company, $user] = $this->setupUserAtRole($role);
            $this->assertTrue($user->can('create', Payment::class),
                "Role {$role->value} harus boleh input payment"
            );
        }

        [$companyV, $viewer] = $this->setupUserAtRole(Role::Viewer);
        $this->assertFalse($viewer->can('create', Payment::class),
            'Viewer tidak boleh input payment'
        );
    }

    // ============================================================
    // Snapshot untuk UI matrix page
    // ============================================================

    public function test_snapshot_grouped_return_semua_permission(): void
    {
        $grouped = RoleMatrix::snapshotGrouped();
        $totalRows = collect($grouped)->flatten(1)->count();
        $this->assertSame(
            count(Permission::cases()),
            $totalRows,
            'Snapshot grouped harus cover semua Permission cases'
        );
    }

    public function test_snapshot_grouped_urutan_sesuai_group_order(): void
    {
        $grouped = RoleMatrix::snapshotGrouped();
        $groupKeys = array_keys($grouped);

        // Kelompok yang tidak ada permission-nya di-filter out;
        // sisanya harus mengikuti urutan groupOrder
        $expectedOrder = array_values(array_intersect(Permission::groupOrder(), $groupKeys));
        $this->assertSame($expectedOrder, $groupKeys);
    }
}
