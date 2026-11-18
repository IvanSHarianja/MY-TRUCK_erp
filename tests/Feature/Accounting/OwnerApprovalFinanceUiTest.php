<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\Tenancy\EditCompanyProfile;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\Debts\Pages\CreateDebt;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Debts\Pages\ViewDebt;
use App\Filament\Resources\Debts\RelationManagers\InstallmentsRelationManager;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\AddendumsRelationManager;
use App\Models\Account;
use App\Models\Company;
use App\Models\Debt;
use App\Models\Project;
use App\Models\ProjectAddendum;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** Smoke test halaman: transfer, hutang, pekerjaan tambah, profil PT. */
class OwnerApprovalFinanceUiTest extends TestCase
{
    private Company $company;
    private User $owner;
    private User $admin;
    private Account $bca;
    private Account $mandiri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner = $this->createTenantUser($this->company);
        $this->admin = $this->createTenantUser($this->company, [], 'admin');
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');

        $mk = fn (string $code, string $name) => Account::create([
            'company_id' => $this->company->id, 'code' => $code, 'parent_code' => '111100',
            'name' => $name, 'category' => 'aset', 'normal_balance' => 'debit', 'role' => 'cash', 'is_active' => true,
        ]);
        $this->bca = $mk('111100-01', 'Bank BCA');
        $this->mandiri = $mk('111100-02', 'Bank Mandiri');
    }

    public function test_jurnal_umum_pindah_dana_admin_draft_owner_setujui(): void
    {
        $this->actingAsTenant($this->admin, $this->company);
        $entry = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->mandiri->id, 'debit' => 2_500_000, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 2_500_000],
        ], ['description' => 'Pindah dana proyek']);
        $biasa = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->postableAccount($this->company, '552500')->id, 'debit' => 6_500, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 6_500],
        ]);

        Livewire::test(ListJournalEntries::class)
            ->assertCanSeeTableRecords([$entry, $biasa])
            ->assertSee('menunggu persetujuan Owner')
            ->assertActionHidden(TestAction::make('post')->table($entry))
            ->assertActionVisible(TestAction::make('post')->table($biasa));
        $this->assertNull(JournalEntryResource::getNavigationBadge(), 'badge hanya untuk Owner');

        $this->actingAsTenant($this->owner, $this->company);
        $this->assertSame('1', JournalEntryResource::getNavigationBadge());
        Livewire::test(ListJournalEntries::class)
            ->filterTable('menunggu_owner', true)
            ->assertCanSeeTableRecords([$entry])
            ->assertCanNotSeeTableRecords([$biasa])
            ->callAction(TestAction::make('post')->table($entry));
        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertNull(JournalEntryResource::getNavigationBadge());
    }

    public function test_halaman_hutang_catat_lihat_dan_bayar(): void
    {
        Livewire::test(ListDebts::class)->assertOk();

        Livewire::test(CreateDebt::class)
            ->fillForm([
                'jenis' => 'bank', 'kreditur' => 'Bank BRI', 'payable_account_id' => $this->postableAccount($this->company, '222110')->id,
                'pokok' => '12.000.000', 'bunga_pct' => 6, 'tenor_bulan' => 6,
                'tanggal_mulai' => now()->toDateString(), 'jatuh_tempo_pertama' => now()->addDays(3)->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $debt = Debt::sole();
        $this->assertCount(6, $debt->installments);

        Livewire::test(ListDebts::class)->assertCanSeeTableRecords([$debt])->assertSee('3 hari lagi');
        Livewire::test(ViewDebt::class, ['record' => $debt->getKey()])->assertOk();

        $first = $debt->installments->first();
        Livewire::test(InstallmentsRelationManager::class, ['ownerRecord' => $debt, 'pageClass' => ViewDebt::class])
            ->mountAction(TestAction::make('pay')->table($first))
            ->setActionData(['paid_date' => now()->toDateString(), 'cash_account_id' => $this->bca->id, 'bunga' => '60.000', 'denda' => '0'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertTrue($first->fresh()->isPaid());
        $this->assertEquals(60_000, $first->fresh()->paid_bunga);
    }

    public function test_viewer_tidak_bisa_bayar_cicilan(): void
    {
        $viewer = $this->createTenantUser($this->company, [], 'viewer');
        $debt = app(\App\Services\Accounting\DebtService::class)->create($this->company, $this->owner, [
            'jenis' => 'lainnya', 'kreditur' => 'Pak Ahmad', 'payable_account_id' => $this->postableAccount($this->company, '222110')->id,
            'pokok' => 1_000_000, 'tenor_bulan' => 1, 'tanggal_mulai' => now()->toDateString(), 'jatuh_tempo_pertama' => now()->toDateString(),
        ]);
        $this->actingAsTenant($viewer, $this->company);
        Livewire::test(InstallmentsRelationManager::class, ['ownerRecord' => $debt, 'pageClass' => ViewDebt::class])
            ->assertActionHidden(TestAction::make('pay')->table($debt->installments->first()));
    }

    public function test_tab_pekerjaan_tambah_ajukan_dan_setujui(): void
    {
        $project = Project::create([
            'company_id' => $this->company->id, 'project_number' => 'PR26-0009', 'name' => 'Jalan Kebun',
            'client_id' => $this->createClient($this->company)->id, 'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak' => 50_000_000, 'progress_pct' => 0, 'tertagih_pct' => 0, 'dp_diterima' => 0,
            'status' => 'berjalan', 'created_by' => $this->owner->id,
        ]);

        $this->actingAsTenant($this->admin, $this->company);
        Livewire::test(AddendumsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->mountAction(TestAction::make('create')->table())
            ->setActionData(['tanggal' => now()->toDateString(), 'nilai' => '4.000.000', 'uraian' => 'Tambah gorong-gorong 2 titik'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $add = ProjectAddendum::sole();
        $this->assertSame('pending', $add->status);

        Livewire::test(AddendumsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->assertActionHidden(TestAction::make('approve')->table($add));

        $this->actingAsTenant($this->owner, $this->company);
        Livewire::test(AddendumsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->callAction(TestAction::make('approve')->table($add));
        $this->assertSame('approved', $add->fresh()->status);
        $this->assertNotNull($add->fresh()->invoice_id);
    }

    public function test_upload_tanda_tangan_hanya_owner(): void
    {
        Livewire::test(EditCompanyProfile::class, ['tenant' => $this->company])
            ->assertFormFieldEnabled('signature_path');

        $this->actingAsTenant($this->admin, $this->company);
        Livewire::test(EditCompanyProfile::class, ['tenant' => $this->company])
            ->assertFormFieldDisabled('signature_path');
    }
}
