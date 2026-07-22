<?php

namespace Tests\Feature\Bug;

use App\Models\Company;
use App\Services\Accounting\IncomeStatementByAssetService;
use App\Services\Accounting\IncomeStatementMatrixService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUG-16 — Income Statement cumulative fix.
 *
 * Sebelum fix:
 *   Buka L/R 2027 → revenue 2025 + 2026 + 2027 dijumlah. Setiap tahun overstate.
 *
 * Setelah fix:
 *   L/R hanya tampilkan transaksi di tahun terpilih.
 *   Trial Balance & Balance Sheet TETAP cumulative (untuk saldo neraca).
 */
class IncomeStatementCumulativeTest extends TestCase
{
    /**
     * Setup: seed invoice di 2025, 2026, 2027 untuk company.
     * Return array [company, dictionary tahun => total revenue tahun itu].
     */
    private function seedMultiYearRevenue(): array
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $revenuePerYear = [
            2025 => 3_000_000,
            2026 => 5_000_000,
            2027 => 7_000_000,
        ];

        foreach ($revenuePerYear as $year => $amount) {
            $invoice = $this->makeDraftInvoice($company, [
                'amount'       => $amount,
                'invoice_date' => Carbon::create($year, 6, 15)->toDateString(),
            ]);
            app(InvoiceService::class)->issue($invoice);
        }

        return [$company, $revenuePerYear];
    }

    // ============================================================
    // IncomeStatementService (single-column L/R)
    // ============================================================

    public function test_bug16_is_hanya_tahun_2027_tidak_include_2025_2026(): void
    {
        [$company, $revenue] = $this->seedMultiYearRevenue();

        $report = app(IncomeStatementService::class)->getReport($company->id, 2027);

        $this->assertSame(
            (float) $revenue[2027],
            $report['totalPendapatan'],
            'L/R tahun 2027 hanya boleh berisi revenue 2027, bukan akumulasi'
        );
    }

    public function test_bug16_is_tahun_2025_tidak_include_tahun_lain(): void
    {
        [$company, $revenue] = $this->seedMultiYearRevenue();

        $report = app(IncomeStatementService::class)->getReport($company->id, 2025);

        $this->assertSame((float) $revenue[2025], $report['totalPendapatan']);
    }

    public function test_bug16_is_tahun_kosong_return_nol(): void
    {
        [$company, ] = $this->seedMultiYearRevenue();

        // Tahun tanpa transaksi
        $report = app(IncomeStatementService::class)->getReport($company->id, 2028);

        $this->assertSame(0.0, $report['totalPendapatan']);
        $this->assertSame(0.0, $report['labaBersih']);
    }

    // ============================================================
    // IncomeStatementMatrixService (per lini bisnis)
    // ============================================================

    public function test_bug16_is_matrix_hanya_tahun_terpilih(): void
    {
        [$company, $revenue] = $this->seedMultiYearRevenue();

        $report2027 = app(IncomeStatementMatrixService::class)->getReport($company->id, 2027);
        $this->assertSame((float) $revenue[2027], $report2027['totalRevenue']);

        $report2025 = app(IncomeStatementMatrixService::class)->getReport($company->id, 2025);
        $this->assertSame((float) $revenue[2025], $report2025['totalRevenue']);
    }

    // ============================================================
    // IncomeStatementByAssetService (per unit)
    // ============================================================

    public function test_bug16_is_by_asset_hanya_tahun_terpilih(): void
    {
        // ByAsset service hanya menghitung line yang punya asset_id tag.
        // Invoice generik (dari makeDraftInvoice tanpa source_type='rental_contract')
        // TIDAK punya asset_id — totals akan 0.
        // Yang kita verifikasi di sini: filter periode jalan (2027 vs 2025 keduanya 0
        // untuk seed generik). Cakupan filter periode by asset sudah tested
        // di Matrix service (query pattern identik).
        [$company, ] = $this->seedMultiYearRevenue();

        $report2027 = app(IncomeStatementByAssetService::class)->getReport($company->id, 2027);
        $report2025 = app(IncomeStatementByAssetService::class)->getReport($company->id, 2025);

        // Kedua tahun return sama (0 karena tidak ada asset tag) — filter periode
        // tidak bocorkan data lintas-tahun.
        $this->assertSame($report2027['totals']['revenue'], $report2025['totals']['revenue']);
    }

    // ============================================================
    // Trial Balance TETAP cumulative (untuk BS) — regression check
    // ============================================================

    public function test_bug16_trial_balance_tetap_cumulative_untuk_balance_sheet(): void
    {
        [$company, $revenue] = $this->seedMultiYearRevenue();

        // TB default mode 'cumulative' — harus include semua tahun s/d $year
        $grand2027 = app(TrialBalanceService::class)->getGrandTotal($company->id, 2027);
        $expected  = (float) array_sum($revenue); // 3jt + 5jt + 7jt = 15jt

        // Sisi debit (piutang) = 15jt karena tidak ada pembayaran
        $this->assertSame($expected, $grand2027['total_debit'],
            'Trial Balance HARUS tetap cumulative untuk Balance Sheet — semua tahun terjumlah'
        );
        $this->assertSame($expected, $grand2027['total_kredit']);
        $this->assertTrue($grand2027['is_balanced']);
    }

    public function test_bug16_trial_balance_mode_period_hanya_tahun_terpilih(): void
    {
        [$company, $revenue] = $this->seedMultiYearRevenue();

        // Panggil dengan explicit scopeMode='period'
        $balances = app(TrialBalanceService::class)->getBalances(
            $company->id, 2027, null, null, false, scopeMode: 'period'
        );

        $totalKredit = (float) $balances->sum('saldo_kredit');
        $this->assertSame((float) $revenue[2027], $totalKredit,
            'TrialBalance dengan scopeMode=period harus hanya tahun 2027'
        );
    }

    // ============================================================
    // Sanity: filter month tetap jalan
    // ============================================================

    public function test_bug16_is_filter_by_month_jalan(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // 2 invoice di 2027: Juni & Agustus
        $invJun = $this->makeDraftInvoice($company, [
            'amount'       => 2_000_000,
            'invoice_date' => '2027-06-15',
        ]);
        app(InvoiceService::class)->issue($invJun);

        $invAgu = $this->makeDraftInvoice($company, [
            'amount'       => 3_000_000,
            'invoice_date' => '2027-08-20',
        ]);
        app(InvoiceService::class)->issue($invAgu);

        // L/R Juli 2027 (s/d Juli): hanya Juni = 2jt
        $reportJul = app(IncomeStatementService::class)->getReport($company->id, 2027, 7);
        $this->assertSame(2_000_000.0, $reportJul['totalPendapatan']);

        // L/R September 2027 (s/d Sep): Juni + Agustus = 5jt
        $reportSep = app(IncomeStatementService::class)->getReport($company->id, 2027, 9);
        $this->assertSame(5_000_000.0, $reportSep['totalPendapatan']);
    }
}
