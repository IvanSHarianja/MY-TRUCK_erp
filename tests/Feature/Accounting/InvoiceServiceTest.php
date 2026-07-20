<?php

namespace Tests\Feature\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Services\Accounting\InvoiceService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TST-03 — Feature test InvoiceService issue/void lifecycle.
 *
 * Cakupan:
 *  - issue(): draft → terbit + journal auto-created (Dr Piutang / Cr Pendapatan)
 *  - issue(): guard non-draft, amount ≤ 0
 *  - issue(): auto-generate invoice_number kalau masih DRAFT- placeholder
 *  - void(): terbit → void + jurnal pembalik balanced
 *  - void(): guard invoice sudah void, guard invoice sudah dibayar
 *  - void(): GL setelah void net = 0 (asli void, pembalik posted)
 *  - Journal setelah issue: pakai akun pendapatan sesuai lini bisnis
 */
class InvoiceServiceTest extends TestCase
{
    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvoiceService::class);
    }

    // ============================================================
    // issue()
    // ============================================================

    public function test_issue_draft_ke_terbit_dan_buat_journal_balanced(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 2_500_000, 'business_unit_code' => 'RENT']);

        $issued = $this->service->issue($invoice);

        $this->assertTrue($issued->isTerbit(), 'Status harus terbit setelah issue');
        $this->assertNotNull($issued->journal_entry_id);
        $this->assertNotNull($issued->revenue_account_id);
        $this->assertNotNull($issued->receivable_account_id);

        $journal = JournalEntry::withoutGlobalScopes()->find($issued->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isPosted());
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(2_500_000.0, $journal->total_debit);
        $this->assertSame(2_500_000.0, $journal->total_kredit);
        $this->assertSame('invoice', $journal->document_type);
    }

    public function test_issue_generate_invoice_number_kalau_masih_draft_placeholder(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, [
            'invoice_number' => 'DRAFT-TEST123',
            'invoice_date'   => '2026-08-15',
        ]);

        $issued = $this->service->issue($invoice);

        $this->assertStringStartsWith('INV2608-', $issued->invoice_number);
        $this->assertNotSame('DRAFT-TEST123', $issued->invoice_number);
    }

    public function test_issue_pertahankan_invoice_number_kalau_bukan_placeholder(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, [
            'invoice_number' => 'INV-CUSTOM-001',
        ]);

        $issued = $this->service->issue($invoice);

        $this->assertSame('INV-CUSTOM-001', $issued->invoice_number);
    }

    public function test_issue_pakai_akun_pendapatan_sesuai_lini_bisnis(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Test setiap lini bisnis map ke akun pendapatan yang benar
        $mapping = [
            'RENT' => '441100', // Pendapatan Sewa Alat Berat
            'ARMD' => '441200', // Pendapatan Ritase Dump Truck
            'MATL' => '441300', // Pendapatan Penjualan Material
            'BONG' => '441400', // Pendapatan Borongan Pengurugan
        ];

        foreach ($mapping as $buCode => $expectedAccountCode) {
            $invoice = $this->makeDraftInvoice($company, [
                'business_unit_code' => $buCode,
                'amount'             => 1_000_000,
            ]);

            $issued = $this->service->issue($invoice);
            $revenueAccount = $issued->revenueAccount;

            $this->assertSame($expectedAccountCode, $revenueAccount->code,
                "Lini {$buCode} harus map ke akun {$expectedAccountCode}, dapat {$revenueAccount->code}"
            );
        }
    }

    public function test_issue_non_draft_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company);
        $this->service->issue($invoice);
        $invoice->refresh();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Hanya invoice DRAFT');
        $this->service->issue($invoice);
    }

    public function test_issue_amount_nol_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Nominal invoice harus lebih dari 0');
        $this->service->issue($invoice);
    }

    // ============================================================
    // void()
    // ============================================================

    public function test_void_terbit_ke_void_dengan_pembalik_balanced(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 3_000_000]);
        $this->service->issue($invoice);
        $invoice->refresh();

        $voided = $this->service->void($invoice, 'Test rollback');

        $this->assertTrue($voided->isVoid());
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($user->id, $voided->voided_by);
        $this->assertSame('Test rollback', $voided->void_reason);

        // Jurnal asli sekarang void, ada pembalik posted
        $originalJournal = JournalEntry::withoutGlobalScopes()->find($voided->journal_entry_id);
        $this->assertTrue($originalJournal->isVoid());
        $this->assertNotNull($originalJournal->reversed_by_id);

        $reverseJournal = JournalEntry::withoutGlobalScopes()->find($originalJournal->reversed_by_id);
        $this->assertTrue($reverseJournal->isPosted());
        $this->assertTrue($reverseJournal->isBalanced());
        $this->assertSame('pembalik', $reverseJournal->document_type);
    }

    public function test_void_invoice_sudah_dibayar_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        $this->service->issue($invoice);
        $invoice->refresh();

        // Simulasi ada payment (langsung update paid_amount, tanpa PaymentService)
        $invoice->update(['paid_amount' => 500_000, 'status' => 'sebagian']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sudah ada pembayarannya');
        $this->service->void($invoice);
    }

    public function test_void_invoice_sudah_void_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company);
        $this->service->issue($invoice);
        $this->service->void($invoice->fresh());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sudah void');
        $this->service->void($invoice->fresh());
    }

    // ============================================================
    // GL integrity setelah full lifecycle
    // ============================================================

    public function test_gl_net_nol_setelah_issue_lalu_void(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 5_000_000]);
        $this->service->issue($invoice);
        $this->service->void($invoice->fresh());

        // Hitung TOTAL debit/kredit dari POSTED entries saja (asli sudah void → tidak dihitung)
        $postedIds = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'posted')
            ->pluck('id');

        $totalDebit  = JournalEntryLine::whereIn('journal_entry_id', $postedIds)->sum('debit');
        $totalKredit = JournalEntryLine::whereIn('journal_entry_id', $postedIds)->sum('kredit');

        // Hanya pembalik yang posted; sisi debit = sisi kredit (5_000_000 masing-masing)
        $this->assertSame(5_000_000.0, (float) $totalDebit);
        $this->assertSame(5_000_000.0, (float) $totalKredit);
    }

    public function test_ordering_line_di_journal_konsisten_piutang_dulu_pendapatan_kedua(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        $issued  = $this->service->issue($invoice);

        $journal = JournalEntry::withoutGlobalScopes()
            ->with('lines.account')
            ->find($issued->journal_entry_id);

        $lines = $journal->lines->sortBy('sort_order')->values();

        // Line 1: Dr Piutang (111200)
        $this->assertGreaterThan(0, (float) $lines[0]->debit);
        $this->assertSame(0.0, (float) $lines[0]->kredit);
        $this->assertSame('111200', $lines[0]->account->code);

        // Line 2: Cr Pendapatan (441xxx)
        $this->assertSame(0.0, (float) $lines[1]->debit);
        $this->assertGreaterThan(0, (float) $lines[1]->kredit);
        $this->assertStringStartsWith('441', $lines[1]->account->code);
    }
}
