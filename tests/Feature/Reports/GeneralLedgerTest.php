<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Filament\Pages\Reports\GeneralLedger;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\TrialBalanceService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Buku Besar: saldo awal, mutasi, saldo berjalan — dan saldo akhir harus
 * sama dengan Neraca Saldo (aturan posted / void / pembalik yang sama).
 */
class GeneralLedgerTest extends TestCase
{
    private Company $company;
    private User $owner;
    private Account $kas;
    private Account $modal;
    private Account $pendapatan;
    private GeneralLedgerService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');

        $this->kas        = $this->postableAccount($this->company, '111110');
        $this->modal      = $this->postableAccount($this->company, '331100');
        $this->pendapatan = $this->postableAccount($this->company, '441200');
        $this->svc = app(GeneralLedgerService::class);

        $journal = app(JournalService::class);
        $post = fn (array $lines, string $date, string $desc = 'Test') => $journal->post(
            $this->makeJournalEntry($this->company, $lines, ['description' => $desc], Carbon::parse($date))
        );

        // Januari: setoran modal 10 jt (saldo awal untuk periode Februari)
        $post([
            ['account_id' => $this->kas->id, 'debit' => 10_000_000, 'kredit' => 0],
            ['account_id' => $this->modal->id, 'debit' => 0, 'kredit' => 10_000_000],
        ], '2026-01-10', 'Setoran modal');

        // Februari: pendapatan 2 jt
        $post([
            ['account_id' => $this->kas->id, 'debit' => 2_000_000, 'kredit' => 0, 'description' => 'Terima ritase KA-01'],
            ['account_id' => $this->pendapatan->id, 'debit' => 0, 'kredit' => 2_000_000],
        ], '2026-02-05');

        // Februari: draft — tidak dihitung
        $this->makeJournalEntry($this->company, [
            ['account_id' => $this->kas->id, 'debit' => 0, 'kredit' => 500_000],
            ['account_id' => $this->pendapatan->id, 'debit' => 500_000, 'kredit' => 0],
        ], [], Carbon::parse('2026-02-10'));

        // Februari: posted lalu di-void — jurnal asli & pembalik tidak dihitung
        $voided = $post([
            ['account_id' => $this->kas->id, 'debit' => 1_000_000, 'kredit' => 0],
            ['account_id' => $this->pendapatan->id, 'debit' => 0, 'kredit' => 1_000_000],
        ], '2026-02-15');
        $journal->void($voided, 'salah input');
    }

    private function feb(?int $accountId, ?int $limit = null): array
    {
        return $this->svc->build($this->company->id, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'), $accountId, $limit);
    }

    public function test_saldo_awal_mutasi_dan_saldo_berjalan_satu_akun(): void
    {
        $gl = $this->feb($this->kas->id);

        $this->assertCount(1, $gl['accounts']);
        $kas = $gl['accounts'][0];
        $this->assertEquals(10_000_000, $kas['opening'], 'saldo awal = jurnal sebelum 1 Feb');
        $this->assertCount(1, $kas['lines'], 'draft, void & pembalik tidak muncul');
        $this->assertSame('Terima ritase KA-01', $kas['lines'][0]['description'], 'keterangan baris diutamakan');
        $this->assertEquals(12_000_000, $kas['lines'][0]['balance']);
        $this->assertEquals([2_000_000, 0, 12_000_000], [$kas['debit'], $kas['kredit'], $kas['closing']]);
    }

    public function test_akun_bersaldo_normal_kredit_ditampilkan_positif(): void
    {
        $modal = $this->feb($this->modal->id)['accounts'][0];

        $this->assertEquals(10_000_000, $modal['opening']);
        $this->assertEquals(10_000_000, $modal['closing']);
        $this->assertSame([], $modal['lines']);
    }

    public function test_saldo_akhir_sama_dengan_neraca_saldo(): void
    {
        $tb = app(TrialBalanceService::class)->getBalances($this->company->id, 2026, 2)->keyBy('account_id');

        foreach ($this->feb(null)['accounts'] as $acc) {
            $this->assertEquals($tb[$acc['id']]->saldo, $acc['closing'], "Saldo akhir {$acc['code']} beda dengan Neraca Saldo");
        }
    }

    public function test_semua_akun_hanya_yang_punya_saldo_atau_mutasi(): void
    {
        $gl = $this->feb(null);

        $this->assertEqualsCanonicalizing(
            [$this->kas->code, $this->modal->code, $this->pendapatan->code],
            array_column($gl['accounts'], 'code'),
        );
        $this->assertEquals(['debit' => 2_000_000, 'kredit' => 2_000_000], $gl['totals']);
    }

    public function test_akun_induk_menggabungkan_sub_akun(): void
    {
        $sub = Account::create([
            'company_id' => $this->company->id, 'code' => $this->kas->code . '-01', 'name' => 'Kas Lapangan B',
            'parent_code' => $this->kas->code, 'category' => 'aset', 'sub_category' => 'aset_lancar',
            'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'is_active' => true,
        ]);
        app(JournalService::class)->post($this->makeJournalEntry($this->company, [
            ['account_id' => $sub->id, 'debit' => 300_000, 'kredit' => 0],
            ['account_id' => $this->pendapatan->id, 'debit' => 0, 'kredit' => 300_000],
        ], [], Carbon::parse('2026-02-20')));

        // 111110 kini induk: data lamanya + sub-akun ikut dalam Buku Besar-nya.
        $gl = $this->feb($this->kas->id);
        $this->assertEqualsCanonicalizing([$this->kas->code, $sub->code], array_column($gl['accounts'], 'code'));
        $this->assertEquals(300_000, collect($gl['accounts'])->firstWhere('id', $sub->id)['closing']);
    }

    public function test_terlalu_banyak_baris_hanya_ringkasan(): void
    {
        $gl = $this->feb(null, limit: 1);

        $this->assertTrue($gl['truncated']);
        $this->assertSame([], $gl['accounts'][0]['lines']);
        $this->assertEquals(12_000_000, collect($gl['accounts'])->firstWhere('id', $this->kas->id)['closing'], 'ringkasan tetap benar');
    }

    public function test_halaman_tampil(): void
    {
        Livewire::test(GeneralLedger::class)
            ->set('data.dari', '2026-02-01')
            ->set('data.sampai', '2026-02-28')
            ->set('data.account_id', $this->kas->id)
            ->assertSee('Saldo awal')
            ->assertSee('Terima ritase KA-01')
            ->assertSee('12.000.000');
    }

    public function test_export_excel_dan_pdf(): void
    {
        $params = ['tenant' => $this->company->slug, 'dari' => '2026-02-01', 'sampai' => '2026-02-28'];

        $this->get(route('export.buku-besar', $params))->assertOk()->assertDownload();
        $this->get(route('pdf.general-ledger', $params + ['account_id' => $this->kas->id]))->assertOk();
    }

    public function test_export_menolak_akun_milik_pt_lain(): void
    {
        $other = $this->createTenant();
        $otherKas = $this->postableAccount($other, '111110');

        $this->get(route('export.buku-besar', [
            'tenant' => $this->company->slug, 'dari' => '2026-02-01', 'sampai' => '2026-02-28', 'account_id' => $otherKas->id,
        ]))->assertNotFound();
    }

    public function test_tanpa_izin_laporan_tidak_bisa_export(): void
    {
        $viewer = $this->createTenantUser($this->company, [], Role::Viewer->value);
        app(\App\Support\RoleAccessManager::class)->set($this->company, Role::Viewer, \App\Enums\Permission::ReportsView, false, $this->owner);
        $this->actingAsTenant($viewer, $this->company);

        $this->assertFalse(GeneralLedger::canAccess());
        $this->get(route('export.buku-besar', ['tenant' => $this->company->slug, 'dari' => '2026-02-01', 'sampai' => '2026-02-28']))
            ->assertForbidden();
    }
}
