<?php

namespace Tests\Feature\Audit;

use App\Enums\MaintenanceType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Pages\UserManagement;
use App\Filament\Resources\ArmadaContracts\Pages\EditArmadaContract;
use App\Filament\Resources\ArmadaContracts\Pages\ListArmadaContracts;
use App\Filament\Resources\ArmadaContracts\RelationManagers\MobilizationsRelationManager;
use App\Filament\Resources\ArmadaContracts\RelationManagers\RitLogsRelationManager;
use App\Filament\Resources\Assets\Pages\ImportAssets;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Models\AccountingPeriod;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\AssetCostPerUnitService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\MaintenanceService;
use App\Services\Accounting\PaymentService;
use App\Services\Accounting\ReceivableReportService;
use App\Services\Export\SopArmadaExporter;
use App\Support\RoleAccessManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Regresi audit Gelombang 1 (17 Nov 2026). */
class AuditWave1Test extends TestCase
{
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');
    }

    private function as(string $role): User
    {
        $u = $this->createTenantUser($this->company, [], $role);
        $this->actingAsTenant($u, $this->company);

        return $u;
    }

    private function truck(): Asset
    {
        return Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-' . random_int(10, 99), 'name' => 'DT', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0,
            'useful_life_months' => 96, 'status' => 'aktif',
        ]);
    }

    private function armadaWithUnbilled(): ArmadaContract
    {
        $c = ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA26-0001',
            'client_id' => $this->createClient($this->company)->id, 'tipe_kontrak' => 'alat_saja',
            'route_description' => 'X', 'satuan_tarif' => 'per_rit', 'tarif_per_rit' => 250_000,
            'billed_rit' => 0, 'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->owner->id,
        ]);
        RitLog::create([
            'company_id' => $this->company->id, 'armada_contract_id' => $c->id, 'asset_id' => $this->truck()->id,
            'log_date' => now()->toDateString(), 'rit_count' => 4, 'created_by' => $this->owner->id,
        ]);

        return $c;
    }

    // ── 1. Izin tombol aksi ─────────────────────────────────────────────

    public function test_viewer_tidak_bisa_menagih_atau_menutup_kontrak(): void
    {
        $c = $this->armadaWithUnbilled();
        $this->as(Role::Viewer->value);

        Livewire::test(ListArmadaContracts::class)
            ->assertActionHidden(TestAction::make('tagih')->table($c))
            ->assertActionHidden(TestAction::make('selesai')->table($c))
            // Panggilan Livewire mentah (seperti dari devtools browser) — harus tetap ditolak.
            ->call('mountAction', 'tagih', [], ['recordKey' => (string) $c->getKey(), 'table' => true])
            ->call('callMountedAction');

        $this->assertSame(0, Invoice::count(), 'aksi tersembunyi tidak boleh tereksekusi');
    }

    public function test_accountant_tetap_bisa_menagih(): void
    {
        $c = $this->armadaWithUnbilled();
        $this->as(Role::Accountant->value);

        // Jalur mentah yang SAMA dengan test Viewer — membuktikan jalur itu
        // benar-benar mengeksekusi aksi, jadi penolakan untuk Viewer bukan kebetulan.
        Livewire::test(ListArmadaContracts::class)
            ->assertActionVisible(TestAction::make('tagih')->table($c))
            ->call('mountAction', 'tagih', [], ['recordKey' => (string) $c->getKey(), 'table' => true])
            ->set('mountedActions.0.data.invoice_date', now()->toDateString())
            ->call('callMountedAction');

        $this->assertSame(1, Invoice::count());
    }

    public function test_viewer_tidak_bisa_input_log_harian_dan_ongkos_gendong(): void
    {
        $c = $this->armadaWithUnbilled();
        $this->as(Role::Viewer->value);
        $args = ['ownerRecord' => $c, 'pageClass' => EditArmadaContract::class];

        Livewire::test(RitLogsRelationManager::class, $args)->assertActionHidden(TestAction::make('create')->table());
        Livewire::test(MobilizationsRelationManager::class, $args)->assertActionHidden(TestAction::make('create')->table());
    }

    public function test_viewer_tidak_bisa_menerbitkan_atau_membayar_invoice(): void
    {
        $draft = $this->makeDraftInvoice($this->company, ['amount' => 1_000_000]);
        $this->as(Role::Viewer->value);

        Livewire::test(ListInvoices::class)
            ->assertActionHidden(TestAction::make('issue')->table($draft))
            ->call('mountAction', 'issue', [], ['recordKey' => (string) $draft->getKey(), 'table' => true])
            ->call('callMountedAction');

        $this->assertSame('draft', $draft->fresh()->status);
    }

    // ── 2. Import, owner terakhir, export Buku Besar ────────────────────

    public function test_batal_import_tidak_bisa_menghapus_file_di_luar_folder_import(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backup-sop/rahasia.xlsx', 'penting');

        Livewire::test(ImportAssets::class)
            ->set('storedFile', 'asset-imports/../backup-sop/rahasia.xlsx')
            ->call('cancel');

        Storage::disk('local')->assertExists('backup-sop/rahasia.xlsx');
    }

    public function test_owner_terakhir_tidak_bisa_menonaktifkan_diri(): void
    {
        Livewire::test(UserManagement::class)
            ->callAction(TestAction::make('editRole')->table($this->owner), ['role' => 'owner', 'is_active' => false]);

        $this->assertTrue((bool) $this->company->users()->where('users.id', $this->owner->id)->first()->pivot->is_active);
    }

    public function test_export_excel_buku_besar_butuh_izin_export(): void
    {
        $viewer = $this->as(Role::Viewer->value);
        app(RoleAccessManager::class)->set($this->company, Role::Viewer, Permission::ReportsExportPdf, false, $this->owner);

        $this->get(route('export.buku-besar', ['tenant' => $this->company->slug, 'dari' => '2026-01-01', 'sampai' => '2026-01-31']))
            ->assertForbidden();
    }

    // ── 3. Nominal invoice bersen ───────────────────────────────────────

    public function test_nominal_bersen_dibulatkan_dan_lunas_tanpa_sisa_palsu(): void
    {
        $inv = app(InvoiceService::class)->issue($this->makeDraftInvoice($this->company, ['amount' => 411_481.18]));

        $this->assertSame('411481.00', Invoice::query()->whereKey($inv->id)->value('amount') . (str_contains((string) Invoice::query()->whereKey($inv->id)->value('amount'), '.') ? '' : '.00'));

        $kas = \App\Models\Account::cashAccountIds($this->company->id);
        app(PaymentService::class)->pay($inv->fresh(), \App\Models\Account::find($kas[0]), $inv->fresh()->amount);

        $this->assertSame('lunas', $inv->fresh()->status);
        $this->assertCount(0, app(ReceivableReportService::class)->outstandingInvoices($this->company->id, Carbon::today()),
            'tidak ada sisa piutang Rp 0,18');
    }

    // ── 4. Jurnal posted & servis di periode tertutup ───────────────────

    public function test_jurnal_posted_tidak_bisa_disimpan_lewat_halaman_edit(): void
    {
        $kas = $this->postableAccount($this->company, '111110');
        $modal = $this->postableAccount($this->company, '331100');
        $entry = app(JournalService::class)->post($this->makeJournalEntry($this->company, [
            ['account_id' => $kas->id, 'debit' => 1_000_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0, 'kredit' => 1_000_000],
        ]));

        Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->set('data.description', 'diubah diam-diam')
            ->call('save');

        $this->assertSame('Test entry', $entry->fresh()->description);
    }

    private function closePeriod(Carbon $d): void
    {
        AccountingPeriod::create([
            'company_id' => $this->company->id, 'period_year' => $d->year, 'period_month' => $d->month,
            'status' => 'closed', 'closed_by' => $this->owner->id, 'closed_at' => now(),
        ]);
    }

    public function test_servis_jasa_di_periode_tertutup_ditolak(): void
    {
        $lalu = now()->subMonthNoOverflow()->startOfMonth()->addDays(9);
        $this->closePeriod($lalu);

        try {
            app(MaintenanceService::class)->log([
                'asset_id' => $this->truck()->id, 'maintenance_date' => $lalu->toDateString(),
                'type' => MaintenanceType::ServiceRutin->value, 'description' => 'Servis', 'cost' => 1_000_000,
            ]);
            $this->fail('Seharusnya ditolak');
        } catch (ValidationException) {
            $this->assertSame(0, AssetMaintenanceLog::count(), 'log tidak tersimpan tanpa jurnal');
        }
    }

    public function test_edit_tanggal_servis_ke_periode_tertutup_ditolak_dan_jurnal_lama_utuh(): void
    {
        $log = app(MaintenanceService::class)->log([
            'asset_id' => $this->truck()->id, 'maintenance_date' => now()->toDateString(),
            'type' => MaintenanceType::ServiceRutin->value, 'description' => 'Servis', 'cost' => 1_000_000,
        ]);
        $jurnalLama = $log->journal_entry_id;
        $lalu = now()->subMonthNoOverflow()->startOfMonth()->addDays(9);
        $this->closePeriod($lalu);

        try {
            app(MaintenanceService::class)->updateLog($log, [
                'maintenance_date' => $lalu->toDateString(), 'type' => MaintenanceType::ServiceRutin->value,
                'description' => 'Servis', 'cost' => 1_000_000,
            ]);
            $this->fail('Seharusnya ditolak');
        } catch (ValidationException) {
            $this->assertSame($jurnalLama, $log->fresh()->journal_entry_id);
            $this->assertSame('posted', \App\Models\JournalEntry::find($jurnalLama)->status);
        }
    }

    // ── 5. Laporan biaya per unit & backup SOP ──────────────────────────

    public function test_biaya_per_unit_membaca_beban_maintenance(): void
    {
        $truck = $this->truck();
        app(MaintenanceService::class)->log([
            'asset_id' => $truck->id, 'maintenance_date' => now()->toDateString(),
            'type' => MaintenanceType::ServiceRutin->value, 'description' => 'Servis', 'cost' => 1_000_000,
        ]);

        $row = collect(app(AssetCostPerUnitService::class)->getReport($this->company->id, now()->year, now()->month)['rows'])
            ->firstWhere('asset_id', $truck->id);

        $this->assertEquals(1_000_000, $row['cost_breakdown']['maintenance']);
    }

    public function test_backup_sop_armada_memuat_ongkos_gendong(): void
    {
        $c = $this->armadaWithUnbilled();
        \App\Models\ArmadaMobilization::create([
            'company_id' => $this->company->id, 'armada_contract_id' => $c->id, 'tanggal' => now()->toDateString(),
            'jenis' => 'mobilisasi', 'alat_diangkut' => 'Excavator PC 75', 'amount' => 2_500_000,
        ]);

        $payload = app(SopArmadaExporter::class)->buildPayload($this->company, now()->startOfYear(), now());
        $keterangan = implode(' ', array_filter($payload['cells']['Kontrak Armada'], fn ($v, $k) => str_starts_with($k, 'AE'), ARRAY_FILTER_USE_BOTH));

        $this->assertStringContainsString('Mobilisasi alat: 1× Rp 2.500.000, belum ditagih Rp 2.500.000', $keterangan);
    }
}
