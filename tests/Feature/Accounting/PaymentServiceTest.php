<?php

namespace Tests\Feature\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PaymentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TST-04 — Feature test PaymentService partial/full/reverse.
 *
 * Cakupan:
 *  - pay(): partial → invoice status 'sebagian', GL balanced Dr Kas / Cr Piutang
 *  - pay(): full (sekaligus / bertahap) → invoice status 'lunas'
 *  - pay(): guard amount ≤ 0, amount > sisa, invoice tidak canReceivePayment
 *  - pay(): guard cash account HEADER
 *  - reverse(): kembalikan paid_amount, status → terbit / sebagian
 *  - reverse(): jurnal pembayaran ikut void (pembalik posted)
 *  - Payment number auto-generate PAY{YY}{MM}-{NNNN}
 */
class PaymentServiceTest extends TestCase
{
    private PaymentService $service;
    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service        = app(PaymentService::class);
        $this->invoiceService = app(InvoiceService::class);
    }

    private function issuedInvoice(int $amount = 1_000_000): \App\Models\Invoice
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => $amount]);
        return $this->invoiceService->issue($invoice);
    }

    // ============================================================
    // pay() — happy path
    // ============================================================

    public function test_pay_partial_status_sebagian_dan_gl_balanced(): void
    {
        $invoice = $this->issuedInvoice(2_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $payment = $this->service->pay($invoice, $kas, 500_000);

        $invoice->refresh();
        $this->assertSame('sebagian', $invoice->status);
        $this->assertSame(500_000.0, (float) $invoice->paid_amount);

        // Payment record
        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertStringStartsWith('PAY', $payment->payment_number);
        $this->assertSame($kas->id, $payment->cash_account_id);

        // Journal Dr Kas / Cr Piutang balanced
        $journal = JournalEntry::withoutGlobalScopes()->find($payment->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isPosted());
        $this->assertTrue($journal->isBalanced());
        $this->assertSame('bkm', $journal->document_type);
        $this->assertSame(500_000.0, $journal->total_debit);
    }

    public function test_pay_full_langsung_status_lunas(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $this->service->pay($invoice, $kas, 1_000_000);

        $invoice->refresh();
        $this->assertSame('lunas', $invoice->status);
        $this->assertSame(1_000_000.0, (float) $invoice->paid_amount);
    }

    public function test_pay_bertahap_sampai_lunas(): void
    {
        $invoice = $this->issuedInvoice(3_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $this->service->pay($invoice, $kas, 1_000_000);
        $invoice->refresh();
        $this->assertSame('sebagian', $invoice->status);
        $this->assertSame(1_000_000.0, (float) $invoice->paid_amount);

        $this->service->pay($invoice, $kas, 1_500_000);
        $invoice->refresh();
        $this->assertSame('sebagian', $invoice->status);
        $this->assertSame(2_500_000.0, (float) $invoice->paid_amount);

        $this->service->pay($invoice, $kas, 500_000);
        $invoice->refresh();
        $this->assertSame('lunas', $invoice->status);
        $this->assertSame(3_000_000.0, (float) $invoice->paid_amount);
    }

    public function test_payment_number_format_PAY_YYMM_NNNN(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $payment = $this->service->pay($invoice, $kas, 100_000, paymentDate: \Illuminate\Support\Carbon::create(2026, 8, 15));

        $this->assertMatchesRegularExpression('/^PAY2608-\d{4}$/', $payment->payment_number);
    }

    // ============================================================
    // pay() — guards
    // ============================================================

    public function test_pay_amount_lebih_dari_sisa_throw(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('melebihi sisa piutang');
        $this->service->pay($invoice, $kas, 1_500_000);
    }

    public function test_pay_amount_nol_throw(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('lebih dari 0');
        $this->service->pay($invoice, $kas, 0);
    }

    public function test_pay_invoice_draft_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Invoice masih draft, belum di-issue
        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        $kas     = $this->postableAccount($company, '111100');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak bisa menerima pembayaran');
        $this->service->pay($invoice, $kas, 100_000);
    }

    public function test_pay_invoice_lunas_throw(): void
    {
        $invoice = $this->issuedInvoice(500_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $this->service->pay($invoice, $kas, 500_000);
        $invoice->refresh();
        $this->assertSame('lunas', $invoice->status);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak bisa menerima pembayaran');
        $this->service->pay($invoice, $kas, 100_000);
    }

    public function test_pay_cash_account_header_throw(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);

        // Bikin child untuk 111100 sehingga jadi HEADER
        \App\Models\Account::withoutGlobalScopes()->create([
            'company_id'         => $invoice->company_id,
            'code'               => '111100-01',
            'parent_code'        => '111100',
            'name'               => 'Kas BCA (test child)',
            'category'           => 'aset',
            'sub_category'       => 'aset_lancar',
            'normal_balance'     => 'debit',
            'cash_flow_category' => 'operasi',
            'tax_type'           => 'non_pajak',
            'is_active'          => true,
        ]);

        $header = \App\Models\Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '111100')
            ->first();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('HEADER');
        $this->service->pay($invoice, $header, 500_000);
    }

    // ============================================================
    // reverse()
    // ============================================================

    public function test_reverse_partial_kembalikan_ke_terbit(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $payment = $this->service->pay($invoice, $kas, 400_000);
        $invoice->refresh();
        $this->assertSame('sebagian', $invoice->status);

        $this->service->reverse($payment, 'test reversal');

        $invoice->refresh();
        $this->assertSame('terbit', $invoice->status);
        $this->assertSame(0.0, (float) $invoice->paid_amount);

        // Payment record dihapus
        $this->assertNull(Payment::withoutGlobalScopes()->find($payment->id));
    }

    public function test_reverse_sebagian_dari_multi_payment_status_tetap_sebagian(): void
    {
        $invoice = $this->issuedInvoice(2_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $payment1 = $this->service->pay($invoice, $kas, 500_000);
        $payment2 = $this->service->pay($invoice, $kas, 700_000);
        $invoice->refresh();
        $this->assertSame('sebagian', $invoice->status);
        $this->assertSame(1_200_000.0, (float) $invoice->paid_amount);

        // Reverse payment2, masih ada payment1 → tetap sebagian
        $this->service->reverse($payment2);
        $invoice->refresh();
        $this->assertSame('sebagian', $invoice->status);
        $this->assertSame(500_000.0, (float) $invoice->paid_amount);
    }

    public function test_reverse_jurnal_payment_ikut_void_dan_bikin_pembalik(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $payment = $this->service->pay($invoice, $kas, 400_000);
        $paymentJournalId = $payment->journal_entry_id;

        $this->service->reverse($payment);

        $paymentJournal = JournalEntry::withoutGlobalScopes()->find($paymentJournalId);
        $this->assertNotNull($paymentJournal);
        $this->assertTrue($paymentJournal->isVoid());
        $this->assertNotNull($paymentJournal->reversed_by_id);

        $reverseJournal = JournalEntry::withoutGlobalScopes()->find($paymentJournal->reversed_by_id);
        $this->assertTrue($reverseJournal->isPosted());
        $this->assertTrue($reverseJournal->isBalanced());
        $this->assertSame('pembalik', $reverseJournal->document_type);
    }

    public function test_reverse_lunas_kembalikan_ke_terbit_paid_amount_nol(): void
    {
        $invoice = $this->issuedInvoice(1_000_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $payment = $this->service->pay($invoice, $kas, 1_000_000);
        $invoice->refresh();
        $this->assertSame('lunas', $invoice->status);

        $this->service->reverse($payment);
        $invoice->refresh();
        $this->assertSame('terbit', $invoice->status);
        $this->assertSame(0.0, (float) $invoice->paid_amount);
    }

    // ============================================================
    // Trial balance integrity
    // ============================================================

    public function test_gl_setelah_issue_pay_reverse_tetap_balanced(): void
    {
        $invoice = $this->issuedInvoice(1_500_000);
        $kas     = $this->postableAccount($invoice->company, '111100');

        $payment = $this->service->pay($invoice, $kas, 750_000);
        $this->service->reverse($payment);

        $postedIds = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('status', 'posted')
            ->pluck('id');

        $totalDebit  = JournalEntryLine::whereIn('journal_entry_id', $postedIds)->sum('debit');
        $totalKredit = JournalEntryLine::whereIn('journal_entry_id', $postedIds)->sum('kredit');

        // Semua entry POSTED harus balanced (issue + payment reverse pembalik)
        $this->assertSame((float) $totalDebit, (float) $totalKredit,
            "Trial balance harus balanced setelah issue+pay+reverse");
    }
}
