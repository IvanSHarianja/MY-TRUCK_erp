<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Enums\Role;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ProjectMarginReport;
use App\Filament\Resources\Projects\RelationManagers\UnitLogsRelationManager;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectUnitLog;
use App\Models\User;
use App\Services\Accounting\ProjectMarginService;
use App\Services\Accounting\ProjectService;
use App\Services\Accounting\ProjectUnitLogService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Pemakaian alat berat & truk sendiri di Proyek Borongan + margin proyek. */
class ProjectUnitLogTest extends TestCase
{
    private Company $company;
    private User $owner;
    private Project $project;
    private Asset $excavator;
    private Asset $truk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant(['harga_solar_default' => 7_000]);
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');

        $this->project = Project::create([
            'company_id' => $this->company->id, 'project_number' => 'PR26-0001', 'name' => 'Urug Lahan',
            'client_id' => $this->createClient($this->company)->id, 'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak' => 100_000_000, 'progress_pct' => 50, 'tertagih_pct' => 0, 'dp_diterima' => 0,
            'status' => 'berjalan', 'created_by' => $this->owner->id,
        ]);
        // Excavator disusutkan per jam: (100jt − 0) / 10.000 jam = 10rb/jam.
        $this->excavator = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'EXC-01', 'name' => 'PC200', 'type' => 'excavator',
            'purchase_date' => '2024-01-01', 'purchase_price' => 100_000_000, 'salvage_value' => 0,
            'depreciation_method' => DepreciationMethod::PerHour->value, 'useful_life_hours' => 10_000, 'status' => 'aktif',
        ]);
        $this->truk = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-01', 'name' => 'Dump', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0, 'useful_life_months' => 96, 'status' => 'aktif',
        ]);
    }

    private function svc(): ProjectUnitLogService
    {
        return app(ProjectUnitLogService::class);
    }

    private function sum(string $code): float
    {
        return (float) DB::table('journal_entry_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $this->company->id)->where('je.status', 'posted')->where('je.document_type', '!=', 'pembalik')
            ->where('a.code', $code)->sum(DB::raw('jl.debit - jl.kredit'));
    }

    public function test_log_alat_berat_menjurnal_biaya_dan_penyusutan_per_jam(): void
    {
        $log = $this->svc()->create($this->project, [
            'log_date' => now()->toDateString(), 'asset_id' => $this->excavator->id, 'jam_kerja' => 8,
            'solar_liter' => 100, 'gaji' => 200_000, 'uang_makan' => 50_000, 'premi' => 30_000,
        ]);

        $this->assertEquals(100 * 7_000, $log->biaya_bbm, 'solar × harga default PT');
        $this->assertEquals(700_000 + 280_000, $log->total_biaya);
        $journal = JournalEntry::find($log->journal_entry_id);
        $this->assertTrue($journal->isBalanced());
        $this->assertSame('BONG', $journal->businessUnit?->code);
        $this->assertEquals(700_000, $this->sum('551100'));
        $this->assertEquals(200_000, $this->sum('552200'));
        $this->assertEquals(80_000, $this->sum('551200'));

        $costs = $this->svc()->projectCosts($this->project);
        $this->assertEquals(80_000, $costs['penyusutan'], '8 jam × 10rb');
    }

    public function test_ubah_log_memposting_ulang_tanpa_dobel(): void
    {
        $log = $this->svc()->create($this->project, [
            'log_date' => now()->toDateString(), 'asset_id' => $this->excavator->id, 'jam_kerja' => 8, 'gaji' => 200_000,
        ]);
        $this->svc()->update($log, [
            'log_date' => now()->toDateString(), 'asset_id' => $this->excavator->id, 'jam_kerja' => 10, 'gaji' => 250_000,
        ]);

        $this->assertEquals(250_000, $this->sum('552200'));
        $this->assertEquals(100_000, $this->svc()->projectCosts($this->project)['penyusutan'], '10 jam × 10rb, yang lama di-void');

        $this->svc()->delete($log->fresh());
        $this->assertEquals(0, $this->sum('552200'));
        $this->assertSame(0, ProjectUnitLog::count());
    }

    public function test_margin_proyek_dari_termin_dikurangi_biaya_unit(): void
    {
        $this->svc()->create($this->project, [
            'log_date' => now()->toDateString(), 'asset_id' => $this->excavator->id, 'jam_kerja' => 8, 'gaji' => 1_000_000,
        ]);
        $this->svc()->create($this->project, [
            'log_date' => now()->toDateString(), 'asset_id' => $this->truk->id, 'rit_count' => 12, 'premi' => 600_000, 'solar_liter' => 50,
        ]);
        app(ProjectService::class)->tagihTermin($this->project->fresh(), 40);

        $row = app(ProjectMarginService::class)->report($this->company->id)['rows']->first();

        $this->assertEquals(40_000_000, $row['pendapatan']);
        $this->assertEquals(1_000_000 + 600_000 + 350_000, $row['biaya_kas']);
        $this->assertEquals(80_000, $row['penyusutan']);
        $this->assertEquals(40_000_000 - 2_030_000, $row['margin']);
        $this->assertCount(2, $row['units']);
    }

    public function test_validasi_pemakaian_periode_dan_unit_pt_lain(): void
    {
        $cases = [
            'tanpa jam & rit' => ['asset_id' => $this->excavator->id],
            'unit PT lain'    => ['asset_id' => Asset::create([
                'company_id' => $this->createTenant()->id, 'asset_code' => 'X', 'name' => 'X', 'type' => 'excavator',
                'purchase_date' => '2024-01-01', 'purchase_price' => 1, 'salvage_value' => 0, 'useful_life_months' => 12, 'status' => 'aktif',
            ])->id, 'jam_kerja' => 5],
        ];
        $last = now()->subMonthNoOverflow();
        AccountingPeriod::create(['company_id' => $this->company->id, 'period_year' => $last->year, 'period_month' => $last->month, 'status' => 'closed']);
        $cases['periode tertutup'] = ['asset_id' => $this->excavator->id, 'jam_kerja' => 5, 'gaji' => 100_000, 'log_date' => $last->startOfMonth()->toDateString()];

        foreach ($cases as $name => $data) {
            try {
                $this->svc()->create($this->project, $data + ['log_date' => now()->toDateString()]);
                $this->fail("{$name} seharusnya ditolak");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, ProjectUnitLog::count());
    }

    public function test_tab_pemakaian_unit_dan_izin(): void
    {
        Livewire::test(UnitLogsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->mountAction(TestAction::make('create')->table())
            ->setActionData(['log_date' => now()->toDateString(), 'asset_id' => $this->truk->id, 'rit_count' => 6, 'premi' => '300000'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertEquals(300_000, ProjectUnitLog::firstOrFail()->total_biaya);

        Livewire::test(ProjectMarginReport::class)->assertOk()->assertSee('PR26-0001');

        $this->actingAsTenant($this->createTenantUser($this->company, [], Role::Viewer->value), $this->company);
        Livewire::test(UnitLogsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->assertActionHidden(TestAction::make('create')->table());
    }
}
