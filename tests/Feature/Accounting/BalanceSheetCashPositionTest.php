<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashFlowLakService;
use App\Services\Accounting\CashFlowService;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: Neraca & Arus Kas saat pemindahan dana antar sub-akun kas.
 *
 *  - Neraca menampilkan rekening bersaldo kredit sebagai "–" dan membuangnya
 *    dari total → aset overstate, neraca tidak balance.
 *  - Neraca Saldo menghitung jurnal 'pembalik' padahal jurnal asli void sudah
 *    ter-exclude → void Kas 1jt tampil sebagai Kas -1jt.
 *  - Laba Rugi membuang pendapatan bersaldo debit dari total.
 *  - Arus Kas melewatkan transaksi yang akun lawannya tanpa cash_flow_category.
 *  - Mutasi antar rekening tidak terlihat di mana pun (sekarang: Posisi Kas).
 */
class BalanceSheetCashPositionTest extends TestCase
{
    private int $year;
    private int $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year  = Carbon::today()->year;
        $this->month = Carbon::today()->month;
    }

    // ============================================================
    // Neraca
    // ============================================================

    public function test_neraca_menampilkan_rekening_minus_dan_tetap_balance(): void
    {
        [$company, $bankA, $bankB] = $this->tenantWithTwoBanks();

        $this->transfer($company, $bankA, $bankB, 3_000_000); // A belum punya saldo awal

        $bs   = app(BalanceSheetService::class)->getReport($company->id, $this->year, $this->month);
        $rows = $bs['asetLancar']->keyBy('code');

        $this->assertEquals(-3_000_000, $rows['111100-01']->nilai, 'Bank A harus tampil minus, bukan kosong');
        $this->assertEquals(3_000_000, $rows['111100-02']->nilai);
        $this->assertEquals(0, $bs['totalAsetLancar'], 'Transfer antar rekening tidak mengubah total aset');
        $this->assertTrue($bs['isBalanced'], "Neraca harus balance (selisih {$bs['selisih']})");
    }

    public function test_akumulasi_penyusutan_tetap_mengurangi_aset_tetap(): void
    {
        [$company] = $this->tenantWithTwoBanks();
        $this->postJournal($company, [
            [$this->postableAccount($company, '552100'), 400_000, 0],
            [$this->postableAccount($company, '112105'), 0, 400_000],
        ]);

        $bs = app(BalanceSheetService::class)->getReport($company->id, $this->year, $this->month);

        $this->assertEquals(-400_000, $bs['asetTetap']->firstWhere('code', '112105')->nilai);
        $this->assertEquals(-400_000, $bs['totalAsetTetap']);
        $this->assertTrue($bs['isBalanced']);
    }

    // ============================================================
    // Void di Neraca Saldo / Neraca / Laba Rugi
    // ============================================================

    public function test_void_jurnal_netral_di_neraca_saldo_neraca_dan_laba_rugi(): void
    {
        [$company, $bankA] = $this->tenantWithTwoBanks();
        $this->saldoAwal($company, $bankA, 10_000_000);

        $sale = $this->postJournal($company, [
            [$bankA, 1_000_000, 0],
            [$this->postableAccount($company, '441100'), 0, 1_000_000],
        ]);
        app(JournalService::class)->void($sale, 'Batal');

        $tb = app(TrialBalanceService::class)->getBalances($company->id, $this->year, $this->month)->keyBy('code');
        $this->assertEquals(10_000_000, $tb['111100-01']->saldo, 'Void harus netral, bukan membalik saldo');
        $this->assertArrayNotHasKey('441100', $tb->all(), 'Pendapatan yang di-void tidak boleh tersisa (minus)');

        $this->assertEquals(0, app(IncomeStatementService::class)->getNetProfit($company->id, $this->year, $this->month));

        $bs = app(BalanceSheetService::class)->getReport($company->id, $this->year, $this->month);
        $this->assertEquals(10_000_000, $bs['totalAset']);
        $this->assertTrue($bs['isBalanced']);
    }

    public function test_laba_rugi_pendapatan_bersaldo_debit_mengurangi_total(): void
    {
        [$company, $bankA] = $this->tenantWithTwoBanks();
        $this->postJournal($company, [[$bankA, 5_000_000, 0], [$this->postableAccount($company, '441100'), 0, 5_000_000]]);
        // Koreksi/retur di akun pendapatan lain → saldo debit
        $this->postJournal($company, [[$this->postableAccount($company, '441200'), 1_000_000, 0], [$bankA, 0, 1_000_000]]);

        $is = app(IncomeStatementService::class)->getReport($company->id, $this->year, $this->month);

        $this->assertEquals(4_000_000, $is['totalPendapatan']);
        $this->assertEquals(-1_000_000, $is['pendapatan']->firstWhere('code', '441200')->nilai);
        $this->assertTrue(app(BalanceSheetService::class)->getReport($company->id, $this->year, $this->month)['isBalanced']);
    }

    // ============================================================
    // Arus Kas + Posisi Kas per Rekening
    // ============================================================

    public function test_posisi_kas_menampilkan_transfer_dan_rekonsiliasi_dengan_arus_kas(): void
    {
        [$company, $bankA, $bankB] = $this->tenantWithTwoBanks();
        $this->saldoAwal($company, $bankA, 10_000_000);
        $this->transfer($company, $bankA, $bankB, 3_000_000);
        $this->postJournal($company, [[$bankB, 2_000_000, 0], [$this->postableAccount($company, '441100'), 0, 2_000_000]]);

        $posisi = app(CashPositionService::class)->getReport($company->id, $this->year, $this->month);
        $rows   = collect($posisi['rows'])->keyBy('code');

        $this->assertEquals(['saldo_awal' => 10_000_000, 'transfer_keluar' => 3_000_000, 'saldo_akhir' => 7_000_000],
            array_intersect_key($rows['111100-01'], array_flip(['saldo_awal', 'transfer_keluar', 'saldo_akhir'])));
        $this->assertEquals(['masuk' => 2_000_000, 'transfer_masuk' => 3_000_000, 'saldo_akhir' => 5_000_000],
            array_intersect_key($rows['111100-02'], array_flip(['masuk', 'transfer_masuk', 'saldo_akhir'])));
        $this->assertEquals($posisi['total']['transfer_masuk'], $posisi['total']['transfer_keluar']);

        $cf = app(CashFlowService::class)->getReport($company->id, $this->year, $this->month);
        $this->assertEquals(2_000_000, $cf['kasMasukOperasi'], 'Transfer tidak dihitung sebagai arus kas');
        $this->assertEquals($cf['saldoAkhir'], $posisi['total']['saldo_akhir'], 'Posisi kas harus rekonsiliasi dengan Arus Kas');
        $this->assertSame($posisi, $cf['posisiKas']);
    }

    public function test_arus_kas_tidak_melewatkan_akun_lawan_tanpa_kategori(): void
    {
        [$company, $bankA] = $this->tenantWithTwoBanks();
        $lainLain = $this->postableAccount($company, '441900');
        DB::table('accounts')->where('id', $lainLain->id)->update(['cash_flow_category' => null]);

        $this->postJournal($company, [[$bankA, 1_000_000, 0], [$lainLain, 0, 1_000_000]]);

        $cf = app(CashFlowService::class)->getReport($company->id, $this->year, $this->month);

        $this->assertEquals(1_000_000, $cf['kasMasukOperasi'], 'Dulu dianggap transfer antar kas → hilang dari laporan');
        $this->assertEquals(1_000_000, $cf['saldoAkhir']);
    }

    public function test_arus_kas_lawan_campuran_kategori_kosong_dan_terisi(): void
    {
        [$company, $bankA] = $this->tenantWithTwoBanks();
        $lainLain = $this->postableAccount($company, '441900');
        DB::table('accounts')->where('id', $lainLain->id)->update(['cash_flow_category' => null]);
        $modal = $this->postableAccount($company, '331100');

        // Urutan line: kategori kosong dulu → dulu [0] undefined setelah filter() → transaksi hilang.
        $this->postJournal($company, [[$bankA, 1_000_000, 0], [$lainLain, 0, 400_000], [$modal, 0, 600_000]]);

        $cf = app(CashFlowService::class)->getReport($company->id, $this->year, $this->month);

        $this->assertEquals(1_000_000, $cf['totalKasMasuk']);
    }

    // ============================================================
    // Render halaman & PDF
    // ============================================================

    public function test_halaman_dan_pdf_neraca_serta_arus_kas_ter_render(): void
    {
        [$company, $bankA, $bankB, $user] = $this->tenantWithTwoBanks();
        $this->transfer($company, $bankA, $bankB, 3_000_000);

        $this->actingAs($user);

        $this->get("/admin/{$company->slug}/balance-sheet")->assertOk()->assertSee('(Rp 3.000.000)');
        $this->get("/admin/{$company->slug}/cash-flow")->assertOk()->assertSee('Posisi Kas per Rekening')->assertSee('Bank A');
        $this->get("/admin/{$company->slug}/cash-flow-lak")->assertOk()->assertSee('Posisi Kas per Rekening');

        foreach (['pdf.balance-sheet', 'pdf.cash-flow', 'pdf.cash-flow-lak', 'pdf.income-statement'] as $route) {
            $this->get(route($route, ['tenant' => $company->slug, 'year' => $this->year, 'month' => $this->month]))
                ->assertOk();
        }

        $lak = app(CashFlowLakService::class)->getReport($company->id, $this->year, $this->month);
        $this->assertCount(2, $lak['posisiKas']['rows']);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /** @return array{0: Company, 1: Account, 2: Account, 3: \App\Models\User} */
    private function tenantWithTwoBanks(): array
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $bank = fn (string $code, string $name) => Account::create([
            'company_id'         => $company->id,
            'code'               => $code,
            'name'               => $name,
            'parent_code'        => '111100',
            'category'           => 'aset',
            'sub_category'       => 'aset_lancar',
            'role'               => 'cash',
            'normal_balance'     => 'debit',
            'cash_flow_category' => 'operasi',
            'is_active'          => true,
        ]);

        return [$company, $bank('111100-01', 'Bank A'), $bank('111100-02', 'Bank B'), $user];
    }

    /** @param array<int, array{0: Account, 1: int, 2: int}> $lines */
    private function postJournal(Company $company, array $lines, string $type = 'manual'): \App\Models\JournalEntry
    {
        $entry = $this->makeJournalEntry(
            $company,
            array_map(fn ($l) => ['account_id' => $l[0]->id, 'debit' => $l[1], 'kredit' => $l[2]], $lines),
            ['document_type' => $type],
        );

        return app(JournalService::class)->post($entry);
    }

    private function transfer(Company $company, Account $from, Account $to, int $amount): void
    {
        $this->postJournal($company, [[$to, $amount, 0], [$from, 0, $amount]]);
    }

    private function saldoAwal(Company $company, Account $bank, int $amount): void
    {
        $this->postJournal($company, [[$bank, $amount, 0], [$this->postableAccount($company, '331100'), 0, $amount]], 'saldo_awal');
    }
}
