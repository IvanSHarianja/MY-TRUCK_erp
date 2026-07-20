<?php

namespace Tests\Feature\Accounting;

use App\Models\Asset;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PaymentService;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TST-07 — Feature test Trial Balance selalu balanced.
 *
 * Prinsip fundamental double-entry: sum(saldo_debit) = sum(saldo_kredit)
 * KAPAN PUN, apa pun kombinasi transaksinya. Kalau ini pernah gagal,
 * ada bug fatal di service layer.
 *
 * Cakupan:
 *  - TB balanced setelah invoice issue
 *  - TB balanced setelah payment
 *  - TB balanced setelah depreciation
 *  - TB balanced setelah void invoice
 *  - TB balanced setelah reverse payment
 *  - TB balanced setelah kombinasi campuran ~15 transaksi
 *  - TB per business_unit_id: sum semua BU harus tetap balanced
 *  - Grand total dari getGrandTotal(): is_balanced=true selalu
 */
class TrialBalanceTest extends TestCase
{
    private TrialBalanceService $tb;
    private InvoiceService $invoices;
    private PaymentService $payments;
    private DepreciationService $depreciation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tb           = app(TrialBalanceService::class);
        $this->invoices     = app(InvoiceService::class);
        $this->payments     = app(PaymentService::class);
        $this->depreciation = app(DepreciationService::class);
    }

    private function assertBalanced(int $companyId, int $year, ?int $month = null, string $ctx = ''): void
    {
        $grand = $this->tb->getGrandTotal($companyId, $year, $month);

        $this->assertTrue(
            $grand['is_balanced'],
            "Trial Balance tidak balance {$ctx}: "
            . sprintf('Dr=%.2f, Cr=%.2f, selisih=%.2f',
                $grand['total_debit'],
                $grand['total_kredit'],
                abs($grand['total_debit'] - $grand['total_kredit'])
            )
        );
    }

    // ============================================================
    // TB balanced per operasi tunggal
    // ============================================================

    public function test_tb_balanced_setelah_invoice_issue(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 5_000_000]);
        $this->invoices->issue($invoice);

        $this->assertBalanced($company->id, 2026, ctx: 'setelah issue');
    }

    public function test_tb_balanced_setelah_payment_full(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 2_000_000]);
        $issued  = $this->invoices->issue($invoice);
        $kas     = $this->postableAccount($company, '111100');

        $this->payments->pay($issued, $kas, 2_000_000);

        $this->assertBalanced($company->id, 2026, ctx: 'setelah full payment');
    }

    public function test_tb_balanced_setelah_void_invoice(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_500_000]);
        $this->invoices->issue($invoice);
        $this->invoices->void($invoice->fresh());

        $this->assertBalanced($company->id, 2026, ctx: 'setelah void invoice');
    }

    public function test_tb_balanced_setelah_reverse_payment(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        $this->invoices->issue($invoice);
        $kas     = $this->postableAccount($company, '111100');
        $payment = $this->payments->pay($invoice->fresh(), $kas, 400_000);

        $this->payments->reverse($payment);

        $this->assertBalanced($company->id, 2026, ctx: 'setelah reverse payment');
    }

    public function test_tb_balanced_setelah_depreciation(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        Asset::create([
            'company_id'         => $company->id,
            'asset_code'         => 'DT-01',
            'name'               => 'Test Dump Truck',
            'type'               => 'dump_truck',
            'purchase_date'      => '2024-01-15',
            'purchase_price'     => 480_000_000,
            'salvage_value'      => 48_000_000,
            'useful_life_months' => 60,
            'status'             => 'aktif',
        ]);

        $this->depreciation->runForCompany($company, 2026, 6);

        $this->assertBalanced($company->id, 2026, 6, ctx: 'setelah depreciation');
    }

    // ============================================================
    // The Stress Test: 15+ transaksi campuran
    // ============================================================

    public function test_tb_balanced_setelah_15_transaksi_campuran(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $kas = $this->postableAccount($company, '111100');

        // Seed 3 asset untuk depresiasi
        for ($i = 1; $i <= 3; $i++) {
            Asset::create([
                'company_id'         => $company->id,
                'asset_code'         => 'DT-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name'               => "Dump Truck {$i}",
                'type'               => 'dump_truck',
                'purchase_date'      => '2023-01-15',
                'purchase_price'     => 400_000_000 + $i * 10_000_000,
                'salvage_value'      => 40_000_000,
                'useful_life_months' => 60,
                'status'             => 'aktif',
            ]);
        }

        // 5 invoice issue di berbagai lini bisnis
        $businessUnits = ['RENT', 'ARMD', 'MATL', 'BONG', 'RENT'];
        $issued = [];
        foreach ($businessUnits as $i => $bu) {
            $invoice = $this->makeDraftInvoice($company, [
                'amount'             => 1_000_000 * ($i + 1),
                'business_unit_code' => $bu,
            ]);
            $issued[] = $this->invoices->issue($invoice);
        }
        $this->assertBalanced($company->id, 2026, ctx: 'setelah 5 invoices issued');

        // 3 payment: full, partial, partial
        $this->payments->pay($issued[0]->fresh(), $kas, 1_000_000);        // Full pada invoice #1 (1jt)
        $p2 = $this->payments->pay($issued[1]->fresh(), $kas, 1_500_000);  // Partial pada invoice #2 (2jt)
        $this->payments->pay($issued[2]->fresh(), $kas, 500_000);          // Partial pada invoice #3 (3jt)
        $this->assertBalanced($company->id, 2026, ctx: 'setelah 3 payments');

        // 1 void invoice (invoice #4 - BONG - 4jt, belum ada payment)
        $this->invoices->void($issued[3]->fresh(), 'Test batal');
        $this->assertBalanced($company->id, 2026, ctx: 'setelah void invoice');

        // 1 reverse payment (partial payment invoice #2)
        $this->payments->reverse($p2);
        $this->assertBalanced($company->id, 2026, ctx: 'setelah reverse payment');

        // 3 depreciation (3 asset di bulan 6)
        $this->depreciation->runForCompany($company, 2026, 6);
        $this->assertBalanced($company->id, 2026, 6, ctx: 'setelah depreciation');

        // 1 payment lagi setelah semua chaos
        $this->payments->pay($issued[4]->fresh(), $kas, 500_000);
        $this->assertBalanced($company->id, 2026, ctx: 'setelah payment final');

        // Total operasi: 5 issue + 3 pay + 1 void + 1 reverse + 3 depreciation + 1 pay = 14 tx
        // Verifikasi ada minimal 14 journal terbentuk
        $totalJournals = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->count();
        $this->assertGreaterThanOrEqual(14, $totalJournals,
            'Total journal harus >= 14 (setiap operasi bikin journal, void + reverse tambah pembalik)'
        );
    }

    // ============================================================
    // TB per business_unit
    // ============================================================

    public function test_tb_per_business_unit_masing_masing_balanced(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Invoice di RENT dan ARMD
        $inv1 = $this->makeDraftInvoice($company, ['amount' => 1_000_000, 'business_unit_code' => 'RENT']);
        $inv2 = $this->makeDraftInvoice($company, ['amount' => 2_000_000, 'business_unit_code' => 'ARMD']);

        $this->invoices->issue($inv1);
        $this->invoices->issue($inv2);

        $rent = $this->businessUnit($company, 'RENT');
        $armd = $this->businessUnit($company, 'ARMD');

        // TB per BU: pakai getBalances() dengan filter business_unit_id
        $balancesRent = $this->tb->getBalances($company->id, 2026, businessUnitId: $rent->id);
        $balancesArmd = $this->tb->getBalances($company->id, 2026, businessUnitId: $armd->id);

        $tbRentDebit  = (float) $balancesRent->sum('saldo_debit');
        $tbRentKredit = (float) $balancesRent->sum('saldo_kredit');
        $tbArmdDebit  = (float) $balancesArmd->sum('saldo_debit');
        $tbArmdKredit = (float) $balancesArmd->sum('saldo_kredit');

        $this->assertSame(round($tbRentDebit, 2), round($tbRentKredit, 2), 'TB RENT harus balanced');
        $this->assertSame(round($tbArmdDebit, 2), round($tbArmdKredit, 2), 'TB ARMD harus balanced');

        // Sum TB di kedua BU harus sesuai total
        $this->assertSame(1_000_000.0, $tbRentDebit);
        $this->assertSame(2_000_000.0, $tbArmdDebit);

        // TB total (semua BU + tanpa filter) juga balanced
        $this->assertBalanced($company->id, 2026, ctx: 'total semua BU');
    }

    // ============================================================
    // Edge: TB kosong (belum ada transaksi)
    // ============================================================

    public function test_tb_kosong_balanced_karena_semua_nol(): void
    {
        $company = $this->createTenant();

        $grand = $this->tb->getGrandTotal($company->id, 2026);

        $this->assertSame(0.0, $grand['total_debit']);
        $this->assertSame(0.0, $grand['total_kredit']);
        $this->assertTrue($grand['is_balanced']);
    }
}
