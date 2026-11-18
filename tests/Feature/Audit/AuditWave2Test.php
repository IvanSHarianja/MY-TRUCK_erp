<?php

namespace Tests\Feature\Audit;

use App\Enums\Role;
use App\Filament\Pages\YearEndClosing;
use App\Models\Account;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\ArmadaContractService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashFlowLakService;
use App\Services\Accounting\CashFlowService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ProjectService;
use App\Services\Accounting\TrialBalanceService;
use App\Services\Accounting\YearEndClosingService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regresi audit Gelombang 2 (17 Nov 2026). */
class AuditWave2Test extends TestCase
{
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
    }

    private function acc(string $code): Account
    {
        return $this->postableAccount($this->company, $code);
    }

    private function postJournal(array $lines, string $date, array $overrides = []): \App\Models\JournalEntry
    {
        return app(JournalService::class)->post(
            $this->makeJournalEntry($this->company, $lines, $overrides, Carbon::parse($date))
        );
    }

    private function bu(string $code): int
    {
        return BusinessUnit::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    // ── 5. Tarif terkunci per log ───────────────────────────────────────

    public function test_perubahan_tarif_tidak_berlaku_mundur_ke_log_lama(): void
    {
        $truck = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-01', 'name' => 'DT', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0,
            'useful_life_months' => 96, 'status' => 'aktif',
        ]);
        $c = ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA26-0001',
            'client_id' => $this->createClient($this->company)->id, 'tipe_kontrak' => 'alat_saja',
            'route_description' => 'X', 'satuan_tarif' => 'per_rit', 'tarif_per_rit' => 250_000,
            'billed_rit' => 0, 'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->owner->id,
        ]);
        $log = fn (int $rit) => RitLog::create([
            'company_id' => $this->company->id, 'armada_contract_id' => $c->id, 'asset_id' => $truck->id,
            'log_date' => now()->toDateString(), 'rit_count' => $rit, 'created_by' => $this->owner->id,
        ]);

        $log(6);
        $c->update(['tarif_per_rit' => 300_000]);   // mis. hasil persetujuan Owner
        $log(4);

        $this->assertEquals(6 * 250_000 + 4 * 300_000, $c->fresh()->unbilledSummary()['usage_value']);
        $inv = app(ArmadaContractService::class)->tagih($c->fresh());

        $this->assertEquals(2_700_000, $inv->amount);
        $this->assertStringContainsString('6 rit @ Rp 250.000 + 4 rit @ Rp 300.000', $inv->description);
    }

    // ── 4. DP proyek dipotong proporsional ──────────────────────────────

    private function project(): Project
    {
        $p = Project::create([
            'company_id' => $this->company->id, 'project_number' => 'PR26-0001', 'name' => 'Urug',
            'client_id' => $this->createClient($this->company)->id, 'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak' => 100_000_000, 'progress_pct' => 100, 'tertagih_pct' => 0, 'dp_diterima' => 0,
            'status' => 'berjalan', 'created_by' => $this->owner->id,
        ]);
        app(ProjectService::class)->terimaDP($p, $this->acc('111110'), 30_000_000);

        return $p->fresh();
    }

    private function saldo(string $code): float
    {
        return (float) (app(TrialBalanceService::class)->getBalances($this->company->id, (int) now()->year)
            ->firstWhere('code', $code)->saldo ?? 0);
    }

    public function test_dp_dipotong_proporsional_dan_tuntas_di_termin_terakhir(): void
    {
        $svc = app(ProjectService::class);
        $p = $this->project();

        $t1 = $svc->tagihTermin($p, 40);
        $this->assertEquals(40_000_000 - 12_000_000, $t1->amount, 'termin 40%: bruto 40jt − DP 12jt');
        $this->assertEquals(18_000_000, $this->saldo('221170'), 'sisa Uang Muka 18jt');

        $t2 = $svc->tagihTermin($p->fresh(), 60);
        $this->assertEquals(60_000_000 - 18_000_000, $t2->amount, 'termin terakhir memotong seluruh sisa DP');
        $this->assertEquals(0, $this->saldo('221170'), 'Uang Muka tuntas');
        $this->assertEquals(100_000_000, $this->saldo('441400'), 'pendapatan = nilai kontrak');
        $this->assertSame('selesai', $p->fresh()->status);
    }

    public function test_void_termin_mengembalikan_potongan_dp(): void
    {
        $svc = app(ProjectService::class);
        $p = $this->project();
        $svc->tagihTermin($p, 40);
        $t2 = $svc->tagihTermin($p->fresh(), 30);

        app(InvoiceService::class)->void($t2, 'salah');

        $this->assertEquals(12_000_000, $p->fresh()->dp_dipotong);
        $this->assertEquals(18_000_000, $this->saldo('221170'));
        $this->assertEquals(40_000_000, $this->saldo('441400'));
    }

    public function test_dp_yang_sudah_dipotong_tidak_bisa_di_void(): void
    {
        $p = $this->project();
        app(ProjectService::class)->tagihTermin($p, 40);
        $dpJournal = \App\Models\JournalEntry::where('document_number', 'DP-' . $p->project_number)->firstOrFail();

        $this->expectException(ValidationException::class);
        app(JournalService::class)->void($dpJournal, 'batal');
    }

    public function test_proyek_lama_termin_terakhir_lunas_dari_dp_tanpa_invoice(): void
    {
        $p = $this->project();
        // Data lama: termin 70% ditagih sebelum ada pemotongan DP.
        Project::withoutGlobalScopes()->whereKey($p->id)->update(['tertagih_pct' => 70]);

        $result = app(ProjectService::class)->tagihTermin($p->fresh(), 30);

        $this->assertFalse($result->exists, 'bersih 0 → tidak ada invoice');
        $this->assertSame(0, Invoice::count());
        $this->assertEquals(0, $this->saldo('221170'));
        $this->assertEquals(100.0, (float) $p->fresh()->tertagih_pct);
    }

    // ── 3. Arus Kas per periode ─────────────────────────────────────────

    public function test_arus_kas_bulan_itu_saja_dan_saldo_akhir_sama_dengan_neraca_saldo(): void
    {
        $kas = $this->acc('111110');
        $pdpt = $this->acc('441100');
        $modal = $this->acc('331100');

        $this->postJournal([['account_id' => $kas->id, 'debit' => 10_000_000, 'kredit' => 0], ['account_id' => $modal->id, 'debit' => 0, 'kredit' => 10_000_000]],
            '2026-01-01', ['document_type' => 'saldo_awal']);
        $this->postJournal([['account_id' => $kas->id, 'debit' => 5_000_000, 'kredit' => 0], ['account_id' => $pdpt->id, 'debit' => 0, 'kredit' => 5_000_000]], '2026-02-10');
        $this->postJournal([['account_id' => $kas->id, 'debit' => 2_000_000, 'kredit' => 0], ['account_id' => $pdpt->id, 'debit' => 0, 'kredit' => 2_000_000]], '2026-03-10');
        // Saldo awal bertanggal bulan depan tidak boleh ikut laporan Maret.
        $this->postJournal([['account_id' => $kas->id, 'debit' => 9_000_000, 'kredit' => 0], ['account_id' => $modal->id, 'debit' => 0, 'kredit' => 9_000_000]],
            '2026-04-01', ['document_type' => 'saldo_awal']);

        $cf = app(CashFlowService::class)->getReport($this->company->id, 2026, 3);

        $this->assertEquals(15_000_000, $cf['saldoAwal'], 'saldo awal = kas per akhir Februari');
        $this->assertEquals(2_000_000, $cf['kasMasukOperasi'], 'hanya kas masuk Maret');
        $kasTb = app(TrialBalanceService::class)->getBalances($this->company->id, 2026, 3)->firstWhere('code', '111110')->saldo;
        $this->assertEquals($kasTb, $cf['saldoAkhir']);
    }

    // ── 2. LAK tanpa hitung dobel ───────────────────────────────────────

    public function test_lak_tidak_menghitung_kas_keluar_dua_kali(): void
    {
        $kas = $this->acc('111110');
        $armd = $this->bu('ARMD');

        $this->postJournal([['account_id' => $kas->id, 'debit' => 100_000_000, 'kredit' => 0], ['account_id' => $this->acc('331100')->id, 'debit' => 0, 'kredit' => 100_000_000]],
            '2026-06-01', ['document_type' => 'saldo_awal']);
        $this->postJournal([['account_id' => $this->acc('552200')->id, 'debit' => 5_000_000, 'kredit' => 0], ['account_id' => $kas->id, 'debit' => 0, 'kredit' => 5_000_000]],
            '2026-06-10', ['business_unit_id' => $armd]);
        $this->postJournal([
            ['account_id' => $this->acc('551100')->id, 'debit' => 1_000_000, 'kredit' => 0],
            ['account_id' => $this->acc('551400')->id, 'debit' => 2_000_000, 'kredit' => 0],
            ['account_id' => $kas->id, 'debit' => 0, 'kredit' => 3_000_000],
        ], '2026-06-12', ['business_unit_id' => $armd]);

        $lak = app(CashFlowLakService::class)->getReport($this->company->id, 2026, 6);

        $this->assertEquals(8_000_000, $lak['totalPengeluaran'] + $lak['tidakTerpetakan']['keluar'], 'tiap rupiah satu kali');
        $this->assertEquals($lak['posisiKas']['total']['saldo_akhir'], $lak['saldoAkhir'], 'saldo akhir LAK = posisi kas');
    }

    // ── 1. Tutup buku tahunan ───────────────────────────────────────────

    private function revenueIn(string $date, int $amount): void
    {
        $this->postJournal([['account_id' => $this->acc('111110')->id, 'debit' => $amount, 'kredit' => 0], ['account_id' => $this->acc('441100')->id, 'debit' => 0, 'kredit' => $amount]], $date);
    }

    public function test_neraca_tahun_kedua_tetap_balance_sebelum_dan_sesudah_tutup_buku(): void
    {
        $this->revenueIn('2025-06-15', 5_000_000);
        $this->revenueIn('2026-03-10', 2_000_000);
        $bs = app(BalanceSheetService::class);

        $before = $bs->getReport($this->company->id, 2026, 3);
        $this->assertTrue($before['isBalanced'], 'dulu selisih 5jt');
        $this->assertEquals(5_000_000, $before['labaTahunLalu']);
        $this->assertSame([2025], $before['tahunBelumDitutup']);

        $journal = app(YearEndClosingService::class)->close($this->company, 2025);
        $this->assertSame('penutup', $journal->document_type);

        $after = $bs->getReport($this->company->id, 2026, 3);
        $this->assertTrue($after['isBalanced']);
        $this->assertEquals(0, $after['labaTahunLalu']);
        $this->assertEquals(2_000_000, $after['labaBerjalan']);
        $this->assertEquals($before['totalEkuitas'], $after['totalEkuitas']);

        // Laporan tahun yang ditutup: L/R tetap 5jt, Neraca Des 2025 tidak dobel.
        $this->assertEquals(5_000_000, app(IncomeStatementService::class)->getNetProfit($this->company->id, 2025, null));
        $dec = $bs->getReport($this->company->id, 2025, 12);
        $this->assertTrue($dec['isBalanced']);
        $this->assertEquals(5_000_000, $dec['totalEkuitas']);
    }

    public function test_tutup_buku_mengunci_periode_dan_bisa_dibuka_kembali(): void
    {
        $this->revenueIn('2025-06-15', 5_000_000);
        $svc = app(YearEndClosingService::class);
        $svc->close($this->company, 2025);

        try {
            $this->revenueIn('2025-12-20', 1_000_000);
            $this->fail('Periode 2025 seharusnya terkunci');
        } catch (ValidationException) {
        }

        $svc->reopen($this->company, 2025, 'koreksi auditor');
        $this->revenueIn('2025-12-20', 1_000_000);

        $bs = app(BalanceSheetService::class)->getReport($this->company->id, 2026, 1);
        $this->assertTrue($bs['isBalanced']);
        $this->assertEquals(6_000_000, $bs['labaTahunLalu']);
    }

    public function test_tutup_buku_berurutan_dan_tidak_untuk_tahun_berjalan(): void
    {
        $this->revenueIn('2024-06-15', 1_000_000);
        $this->revenueIn('2025-06-15', 1_000_000);
        $svc = app(YearEndClosingService::class);

        try {
            $svc->close($this->company, 2025);
            $this->fail('2024 belum ditutup');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2024', collect($e->errors())->flatten()->first());
        }

        $this->expectException(ValidationException::class);
        $svc->close($this->company, (int) now()->year);
    }

    public function test_hanya_owner_yang_bisa_membuka_halaman_tutup_buku(): void
    {
        $this->assertTrue(YearEndClosing::canAccess());

        $this->actingAsTenant($this->createTenantUser($this->company, [], Role::Admin->value), $this->company);
        $this->assertFalse(YearEndClosing::canAccess());
    }
}
