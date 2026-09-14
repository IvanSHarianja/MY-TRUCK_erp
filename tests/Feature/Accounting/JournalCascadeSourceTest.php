<?php

namespace Tests\Feature\Accounting;

use App\Enums\DepreciationMethod;
use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ProjectService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression: cascade void ditentukan dari No. Bukti yang bisa diketik bebas.
 *
 * Sebelum fix:
 *   - Void jurnal manual ber-No. Bukti "DP-{project_number}" memotong dp_diterima proyek.
 *   - Void jurnal manual ber-No. Bukti "BBK-RL-{id}" mem-void DEPUSE milik log asli.
 *   - RentalLog #N dan RitLog #N sama-sama "DEPUSE-{asset}-N" → void/edit satu
 *     log ikut mem-void DEPUSE log tabel lain.
 *
 * Setelah fix: cascade lewat link struktural (FK balik / journal_entries.source_*).
 */
class JournalCascadeSourceTest extends TestCase
{
    // ============================================================
    // DP proyek
    // ============================================================

    public function test_void_jurnal_manual_berno_bukti_dp_tidak_mengubah_dp_proyek(): void
    {
        [$company, $user] = $this->tenant();
        $project = $this->makeProject($company, $user);
        app(ProjectService::class)->terimaDP($project, $this->postableAccount($company, '111100'), 10_000_000);

        // Jurnal koreksi manual yang kebetulan memakai No. Bukti sama.
        $manual = $this->postedManualJournal($company, 'DP-' . $project->project_number, 2_500_000);
        app(JournalService::class)->void($manual, 'Koreksi');

        $this->assertEquals(10_000_000, (float) $project->refresh()->dp_diterima,
            'Void jurnal manual tidak boleh memotong dp_diterima proyek');
    }

    public function test_void_jurnal_dp_sistem_tetap_rollback_dp(): void
    {
        [$company, $user] = $this->tenant();
        $project = $this->makeProject($company, $user);
        $dpJournal = app(ProjectService::class)->terimaDP($project, $this->postableAccount($company, '111100'), 10_000_000);

        $this->assertTrue($dpJournal->sourceIs(Project::class));
        $this->assertSame($project->id, $dpJournal->source_id);

        app(JournalService::class)->void($dpJournal, 'Batal DP');

        $this->assertEquals(0, (float) $project->refresh()->dp_diterima);
    }

    // ============================================================
    // BBK log + DEPUSE
    // ============================================================

    public function test_depuse_menyimpan_source_log(): void
    {
        [$company, $user] = $this->tenant();
        $rentalLog = $this->makeRentalLogWithCost($company, $user);

        $depuse = $this->depuseOf($company, $rentalLog);

        $this->assertNotNull($depuse);
        $this->assertTrue($depuse->sourceIs(RentalLog::class));
        $this->assertSame($rentalLog->id, $depuse->source_id);
    }

    public function test_void_bbk_rental_log_cascade_void_depuse_log_itu(): void
    {
        [$company, $user] = $this->tenant();
        $rentalLog = $this->makeRentalLogWithCost($company, $user);
        $bbk = JournalEntry::withoutGlobalScopes()->find($rentalLog->journal_entry_id);

        app(JournalService::class)->void($bbk, 'Test cascade');

        $this->assertSame('void', $this->depuseOf($company, $rentalLog)->status,
            'DEPUSE harus ikut void saat BBK-RL milik log yang sama di-void');
        $this->assertNull($rentalLog->refresh()->journal_entry_id);
    }

    public function test_void_bbk_rental_log_tidak_void_depuse_rit_log_dengan_id_sama(): void
    {
        [$company, $user] = $this->tenant();
        $rentalLog = $this->makeRentalLogWithCost($company, $user);
        $ritLog    = $this->makeRitLogWithId($company, $user, $rentalLog->id);

        $this->assertSame($rentalLog->id, $ritLog->id, 'Prasyarat: id kedua log sama');
        $this->assertSame('posted', $this->depuseOf($company, $ritLog)->status);

        $bbk = JournalEntry::withoutGlobalScopes()->find($rentalLog->journal_entry_id);
        app(JournalService::class)->void($bbk, 'Test cascade');

        $this->assertSame('void', $this->depuseOf($company, $rentalLog)->status);
        $this->assertSame('posted', $this->depuseOf($company, $ritLog)->status,
            'DEPUSE RitLog tidak boleh ikut void hanya karena id-nya sama dengan RentalLog');
    }

    public function test_edit_rental_log_tidak_void_depuse_rit_log_dengan_id_sama(): void
    {
        [$company, $user] = $this->tenant();
        $rentalLog = $this->makeRentalLogWithCost($company, $user);
        $ritLog    = $this->makeRitLogWithId($company, $user, $rentalLog->id);

        // Jalur RentalLogObserver::updated → voidDepreciationJournalsForLog
        $rentalLog->update(['hm_akhir' => 112, 'jam_kerja' => 12]);

        $this->assertSame('posted', $this->depuseOf($company, $ritLog)->status,
            'Edit RentalLog tidak boleh mem-void DEPUSE RitLog ber-id sama');

        $rentalDepuses = JournalEntry::withoutGlobalScopes()->fromSource($rentalLog)->get();
        $this->assertSame(1, $rentalDepuses->where('status', 'void')->count(), 'DEPUSE lama di-void');
        $this->assertSame(1, $rentalDepuses->where('status', 'posted')->count(), 'DEPUSE baru di-post');
    }

    public function test_void_jurnal_manual_berno_bukti_bbk_rl_tidak_menyentuh_log_asli(): void
    {
        [$company, $user] = $this->tenant();
        $rentalLog = $this->makeRentalLogWithCost($company, $user);
        $bbkId     = $rentalLog->journal_entry_id;

        $manual = $this->postedManualJournal($company, 'BBK-RL-' . $rentalLog->id, 100_000);
        app(JournalService::class)->void($manual, 'Koreksi');

        $this->assertSame('posted', $this->depuseOf($company, $rentalLog)->status,
            'DEPUSE log asli tidak boleh ikut void karena jurnal manual');
        $this->assertSame($bbkId, $rentalLog->refresh()->journal_entry_id,
            'Link BBK log asli tidak boleh di-nullify');
    }

    // ============================================================
    // Backfill migration
    // ============================================================

    public function test_backfill_memetakan_source_dp_dan_depuse_existing(): void
    {
        [$company, $user] = $this->tenant();
        $project   = $this->makeProject($company, $user);
        $dpJournal = app(ProjectService::class)->terimaDP($project, $this->postableAccount($company, '111100'), 5_000_000);
        $rentalLog = $this->makeRentalLogWithCost($company, $user);
        $ritLog    = $this->makeRitLogWithId($company, $user, $rentalLog->id);
        $manualDp  = $this->postedManualJournal($company, 'DP-' . $project->project_number, 1_000_000, 'bkm');

        // Simulasi data sebelum migration: source kosong.
        DB::table('journal_entries')->update(['source_type' => null, 'source_id' => null]);

        $migration = require database_path('migrations/2026_09_14_100000_add_source_to_journal_entries.php');
        foreach (['backfillProjectDp', 'backfillDepuse'] as $method) {
            (new \ReflectionMethod($migration, $method))->invoke($migration);
        }

        $this->assertTrue($dpJournal->refresh()->sourceIs(Project::class));
        $this->assertTrue($this->depuseOf($company, $rentalLog)->sourceIs(RentalLog::class));
        $this->assertTrue($this->depuseOf($company, $ritLog)->sourceIs(RitLog::class),
            'DEPUSE ber-id log sama harus dipetakan ke tabel yang asset_id-nya cocok');

        // Keterbatasan yang didokumentasikan: jurnal manual lama yang persis
        // "DP-{project_number}" + tipe bkm tidak bisa dibedakan saat backfill.
        $this->assertTrue($manualDp->refresh()->sourceIs(Project::class));
    }

    // ============================================================
    // No. Bukti sistem di jurnal manual
    // ============================================================

    public function test_format_no_bukti_sistem_terdeteksi(): void
    {
        $reserved = [
            'DEPUSE-3-12', 'dep-3-202609', 'DP-PR26-0001', 'INV2609-0001', 'PAY2609-0001',
            'PB2609-0001', 'PJ2609-0001', 'BBK-RL-5', 'bbk-rt-7', 'HPP-PJ2609-0001',
            'REV-J2609-001', '  DEPUSE-1-1  ',
        ];
        $allowed = [
            'PBB-2026', 'DP-SUPPLIER-01', 'BKK-001', 'BKM2609-0001', 'BBK2609-0001',
            'INV-0001-01', 'DEPOSIT-01', 'REV-01', '', null,
        ];

        foreach ($reserved as $number) {
            $this->assertTrue(JournalEntry::isSystemDocumentNumber($number), "[{$number}] harus dicadangkan");
        }
        foreach ($allowed as $number) {
            $this->assertFalse(JournalEntry::isSystemDocumentNumber($number), '[' . var_export($number, true) . '] harus diizinkan');
        }
    }

    public function test_form_jurnal_manual_menolak_no_bukti_sistem(): void
    {
        [$company, $user] = $this->tenant();
        $this->bootPanel($company);

        Livewire::test(CreateJournalEntry::class)
            ->fillForm(['document_number' => 'DEPUSE-1-1'])
            ->call('create')
            ->assertHasFormErrors(['document_number']);

        Livewire::test(CreateJournalEntry::class)
            ->fillForm(['document_number' => 'PBB-2026'])
            ->call('create')
            ->assertHasNoFormErrors(['document_number']);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /** @return array{0: Company, 1: User} */
    private function tenant(): array
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        return [$company, $user];
    }

    private function bootPanel(Company $company): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company, isQuiet: true);
        Filament::bootCurrentPanel();
    }

    private function postedManualJournal(Company $company, string $documentNumber, int $amount, string $type = 'manual'): JournalEntry
    {
        $entry = $this->makeJournalEntry(
            $company,
            [
                ['account_id' => $this->postableAccount($company, '111100')->id, 'debit' => $amount, 'kredit' => 0],
                ['account_id' => $this->postableAccount($company, '551100')->id, 'debit' => 0, 'kredit' => $amount],
            ],
            ['document_number' => $documentNumber, 'document_type' => $type],
        );

        return app(JournalService::class)->post($entry);
    }

    private function makeProject(Company $company, User $user): Project
    {
        return Project::create([
            'company_id'      => $company->id,
            'project_number'  => 'PR26-' . random_int(1000, 9999),
            'name'            => 'Test Proyek Urug',
            'client_id'       => $this->createClient($company)->id,
            'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak'   => 100_000_000,
            'progress_pct'    => 0,
            'tertagih_pct'    => 0,
            'dp_diterima'     => 0,
            'status'          => 'berjalan',
            'created_by'      => $user->id,
        ]);
    }

    private function makeAsset(Company $company, DepreciationMethod $method): Asset
    {
        return Asset::create([
            'company_id'          => $company->id,
            'asset_code'          => 'AST-' . strtoupper(Str::random(4)),
            'name'                => 'Test ' . $method->value,
            'type'                => $method === DepreciationMethod::PerRit ? 'dump_truck' : 'excavator',
            'purchase_date'       => '2024-01-15',
            'purchase_price'      => 600_000_000,
            'salvage_value'       => 60_000_000,
            'depreciation_method' => $method->value,
            'useful_life_hours'   => 10_000,
            'useful_life_rits'    => 10_000,
            'status'              => 'aktif',
        ]);
    }

    /** RentalLog all-in (ada biaya BBM → BBK-RL) di aset per_hour (→ DEPUSE). */
    private function makeRentalLogWithCost(Company $company, User $user): RentalLog
    {
        $asset = $this->makeAsset($company, DepreciationMethod::PerHour);

        $contract = RentalContract::create([
            'company_id'          => $company->id,
            'contract_number'     => 'RC-' . strtoupper(Str::random(4)),
            'client_id'           => $this->createClient($company)->id,
            'asset_id'            => $asset->id,
            'tipe_rental'         => 'all_in',
            'include_bbm'         => true,
            'include_operator'    => false,
            'bbm_liter_per_jam'   => 10,
            'harga_bbm_per_liter' => 10_000,
            'tarif_per_jam'       => 500_000,
            'billed_jam'          => 0,
            'status'              => 'aktif',
            'started_at'          => '2026-01-01',
            'created_by'          => $user->id,
        ]);

        $log = RentalLog::create([
            'company_id'         => $company->id,
            'rental_contract_id' => $contract->id,
            'asset_id'           => $asset->id,
            'log_date'           => now()->toDateString(),
            'hm_awal'            => 100,
            'hm_akhir'           => 110,
            'jam_kerja'          => 10,
            'created_by'         => $user->id,
        ])->refresh();

        $this->assertNotNull($log->journal_entry_id, 'Prasyarat: RentalLog all-in harus menghasilkan BBK-RL');

        return $log;
    }

    /** RitLog di aset per_rit (→ DEPUSE) dengan id dipaksa sama dengan RentalLog. */
    private function makeRitLogWithId(Company $company, User $user, int $id): RitLog
    {
        $contract = ArmadaContract::create([
            'company_id'        => $company->id,
            'contract_number'   => 'AC-' . strtoupper(Str::random(4)),
            'client_id'         => $this->createClient($company)->id,
            'tipe_kontrak'      => 'alat_saja',
            'include_bbm'       => false,
            'include_operator'  => false,
            'route_description' => 'Quarry - Proyek',
            'tarif_per_rit'     => 300_000,
            'billed_rit'        => 0,
            'status'            => 'aktif',
            'started_at'        => '2026-01-01',
            'created_by'        => $user->id,
        ]);

        $log = (new RitLog)->forceFill([
            'id'                 => $id,
            'company_id'         => $company->id,
            'armada_contract_id' => $contract->id,
            'asset_id'           => $this->makeAsset($company, DepreciationMethod::PerRit)->id,
            'log_date'           => now()->toDateString(),
            'rit_count'          => 5,
            'created_by'         => $user->id,
        ]);
        $log->save();

        return $log;
    }

    private function depuseOf(Company $company, RentalLog|RitLog $log): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', sprintf('DEPUSE-%d-%d', $log->asset_id, $log->id))
            ->latest('id')
            ->first();
    }
}
