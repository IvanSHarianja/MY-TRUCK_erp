<?php

namespace Tests\Feature\Audit;

use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Models\Account;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Material;
use App\Models\Project;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use App\Services\Accounting\ProjectUnitLogService;
use App\Services\Accounting\JournalService;
use App\Services\ContractChangeService;
use App\Support\DocumentSequence;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Audit ulang 2026-11-18 — gelombang 3 (prioritas rendah). */
class AuditWave6Test extends TestCase
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

    private function truck(): Asset
    {
        return Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-' . random_int(10, 99), 'name' => 'Dump', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0, 'useful_life_months' => 96, 'status' => 'aktif',
        ]);
    }

    public function test_nomor_dokumen_bentrok_dicoba_ulang(): void
    {
        $calls = 0;
        $result = DocumentSequence::createWithRetry(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \Illuminate\Database\UniqueConstraintViolationException('sqlite', 'insert', [], new \Exception('duplicate'));
            }

            return 'ok';
        });
        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function test_setujui_ditolak_bila_draft_diubah_setelah_jendela_dibuka(): void
    {
        $this->actingAsTenant($this->admin, $this->company);
        $entry = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->mandiri->id, 'debit' => 1_000_000, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 1_000_000],
        ]);

        $this->actingAsTenant($this->owner, $this->company);
        $page = Livewire::test(ListJournalEntries::class)->mountAction(TestAction::make('post')->table($entry));

        // Admin menaikkan nominal saat Owner masih membaca jendela konfirmasi.
        JournalEntryLine::where('journal_entry_id', $entry->id)->where('debit', '>', 0)->update(['debit' => 9_000_000]);
        JournalEntryLine::where('journal_entry_id', $entry->id)->where('kredit', '>', 0)->update(['kredit' => 9_000_000]);

        $page->callMountedAction();
        $this->assertSame('draft', $entry->fresh()->status, 'yang diubah tidak boleh ikut terposting');

        Livewire::test(ListJournalEntries::class)->callAction(TestAction::make('post')->table($entry));
        $this->assertSame('posted', $entry->fresh()->status, 'dibuka ulang & diperiksa → boleh');
    }

    public function test_kontrak_tidak_bisa_dibatalkan_bila_ada_log_belum_ditagih(): void
    {
        $c = ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA26-0009',
            'client_id' => $this->createClient($this->company)->id, 'tipe_kontrak' => 'alat_saja',
            'route_description' => 'Kuari → Proyek', 'tarif_per_rit' => 250_000, 'billed_rit' => 0,
            'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->owner->id,
        ]);
        RitLog::create([
            'company_id' => $this->company->id, 'armada_contract_id' => $c->id, 'asset_id' => $this->truck()->id,
            'log_date' => now()->toDateString(), 'rit_count' => 4, 'created_by' => $this->owner->id,
        ]);
        $form = ['client_id' => $c->client_id, 'started_at' => '2026-01-01', 'status' => 'batal',
            'route_description' => 'Kuari → Proyek', 'tarif_per_rit' => '250000', 'tipe_kontrak' => 'alat_saja', 'notes' => null];

        try {
            app(ContractChangeService::class)->submit($c, $form, 'Klien batal kerja sama', $this->owner);
            $this->fail('Harus ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('belum ditagih', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame('aktif', $c->fresh()->status);
    }

    public function test_file_tanda_tangan_lama_dihapus_saat_diganti(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('company-signatures/lama.png', 'x');
        Storage::disk('local')->put('company-signatures/baru.png', 'y');
        $this->company->update(['signature_path' => 'company-signatures/lama.png']);

        $this->company->fresh()->update(['signature_path' => 'company-signatures/baru.png']);

        Storage::disk('local')->assertMissing('company-signatures/lama.png');
        Storage::disk('local')->assertExists('company-signatures/baru.png');
    }

    public function test_jurnal_pemakaian_unit_tidak_bisa_divoid_dari_jurnal_umum(): void
    {
        $project = Project::create([
            'company_id' => $this->company->id, 'project_number' => 'PR26-0001', 'name' => 'Urug',
            'client_id' => $this->createClient($this->company)->id, 'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak' => 100_000_000, 'progress_pct' => 0, 'tertagih_pct' => 0, 'dp_diterima' => 0,
            'status' => 'berjalan', 'created_by' => $this->owner->id,
        ]);
        $log = app(ProjectUnitLogService::class)->create($project, [
            'log_date' => now()->toDateString(), 'asset_id' => $this->truck()->id, 'rit_count' => 5, 'gaji' => 150_000,
        ]);

        try {
            app(JournalService::class)->void(JournalEntry::find($log->journal_entry_id), 'coba');
            $this->fail('Harus ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Pemakaian Unit Harian', collect($e->errors())->flatten()->implode(' '));
        }

        app(ProjectUnitLogService::class)->delete($log);   // lewat menunya tetap bisa
        $this->assertSame(0, \App\Models\ProjectUnitLog::count());
    }

    public function test_batal_penjualan_mengembalikan_stok_dengan_biaya_saat_keluar(): void
    {
        $material = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $buy = fn (int $qty, int $price) => app(MaterialPurchaseService::class)->record([
            'material_id' => $material->id, 'purchase_date' => now()->toDateString(),
            'qty' => $qty, 'unit_price' => $price, 'payment_method' => 'tunai', 'cash_account_id' => $this->bca->id,
        ]);
        $buy(10, 100_000);                                           // MAC 100rb
        $sale = app(MaterialSaleService::class)->create([
            'material_id' => $material->id, 'client_id' => $this->createClient($this->company)->id,
            'sale_date' => now()->toDateString(), 'metode' => 'tunai', 'cash_account_id' => $this->bca->id,
            'volume' => 5, 'harga_satuan' => 150_000,
        ]);                                                         // keluar 5 @100rb, sisa 5
        $buy(5, 200_000);                                            // stok 10, MAC 150rb

        app(MaterialSaleService::class)->voidTunai($sale, $this->owner, 'batal');

        // Stok 15; nilai = 10×150rb + 5×100rb = 2jt → MAC 133.333,33
        $m = $material->fresh();
        $this->assertEquals(15, (float) $m->current_stock);
        $this->assertEqualsWithDelta(133_333.3333, (float) $m->current_mac, 0.001);
    }
}
