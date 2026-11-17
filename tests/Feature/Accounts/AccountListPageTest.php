<?php

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Widgets\AccountRoleReadinessNotice;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\CoaImportService;
use App\Support\RoleAccessManager;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Daftar Akun: peringatan Peran ringkas, tombol Sub-Akun mengikuti izin,
 * filter "Perlu Diperiksa", dan tanpa N+1 query per baris.
 */
class AccountListPageTest extends TestCase
{
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        Filament::setCurrentPanel('admin');
    }

    private function account(string $code): Account
    {
        return Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    public function test_halaman_terbuka_dan_akun_induk_ditandai_induk(): void
    {
        $this->actingAsTenant($this->owner, $this->company);
        $header = $this->account('111100')->parent_code
            ? Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $this->account('111100')->parent_code)->first()
            : null;

        $lw = Livewire::test(ListAccounts::class)->assertOk();

        if ($header) {
            $lw->assertTableColumnStateSet('hierarchy_status', 'Induk', $header);
        }
        $lw->assertTableColumnStateSet('hierarchy_status', 'Transaksi', $this->account('111110'));
    }

    public function test_tombol_sub_akun_disembunyikan_tanpa_izin_coa(): void
    {
        $viewer = $this->createTenantUser($this->company, [], Role::Viewer->value);
        $this->actingAsTenant($viewer, $this->company);

        Livewire::test(ListAccounts::class)
            ->assertTableActionHidden('add_child', $this->account('111100'));
    }

    public function test_accountant_yang_diberi_izin_coa_melihat_tombol_sub_akun(): void
    {
        $accountant = $this->createTenantUser($this->company, [], Role::Accountant->value);
        $this->actingAsTenant($accountant, $this->company);

        Livewire::test(ListAccounts::class)->assertTableActionHidden('add_child', $this->account('111100'));

        app(RoleAccessManager::class)->set($this->company, Role::Accountant, Permission::ChartOfAccountsManage, true, $this->owner);

        Livewire::test(ListAccounts::class)->assertTableActionVisible('add_child', $this->account('111100'));
    }

    public function test_filter_peran_tebakan(): void
    {
        $this->actingAsTenant($this->owner, $this->company);
        $guessed = $this->account('111110');
        $guessed->update(['notes' => CoaImportService::GUESSED_ROLE_NOTE]);

        Livewire::test(ListAccounts::class)
            ->filterTable('perhatian', 'tebakan')
            ->assertCanSeeTableRecords([$guessed])
            ->assertCountTableRecords(1);
    }

    public function test_peringatan_menyebut_fitur_yang_perannya_hilang(): void
    {
        $this->actingAsTenant($this->owner, $this->company);

        Livewire::test(AccountRoleReadinessNotice::class)
            ->assertDontSee('fitur belum bisa membuat jurnal otomatis');

        // Simulasikan COA hasil import: Pendapatan Ritase tanpa Peran & bukan kode standar.
        Account::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('role', AccountRole::RevenueArmd->value)
            ->update(['role' => null, 'code' => DB::raw("CONCAT('9', code)")]);

        Livewire::test(AccountRoleReadinessNotice::class)
            ->assertSee('fitur belum bisa membuat jurnal otomatis')
            ->assertSee('Kontrak Armada')
            ->assertSee(AccountRole::RevenueArmd->label());
    }

    public function test_daftar_akun_tidak_query_per_baris(): void
    {
        $this->actingAsTenant($this->owner, $this->company);
        $rows = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->count();

        DB::enableQueryLog();
        Livewire::test(ListAccounts::class)->set('tableRecordsPerPage', 'all');
        $log = DB::getQueryLog();
        $queries = count($log);
        DB::disableQueryLog();
        if ($queries >= $rows) {
            $counts = collect($log)->countBy('query')->sortDesc()->take(8);
            fwrite(STDERR, print_r($counts->all(), true));
        }

        $this->assertLessThan($rows, $queries, "{$queries} query untuk {$rows} akun — ada query per baris (N+1).");
    }
}
