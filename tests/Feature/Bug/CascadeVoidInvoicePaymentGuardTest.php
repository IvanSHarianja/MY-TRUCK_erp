<?php

namespace Tests\Feature\Bug;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PaymentService;
use Tests\TestCase;

/**
 * BUG-03 — Void journal invoice bypass payment guard.
 *
 * Sebelum fix:
 *   Admin buka halaman Journal, void jurnal invoice yang sudah punya payment.
 *   Cascade set invoice.status=void, tapi payment records tetap ada.
 *   Hasil: kas naik dari payment ke piutang yang sudah dibatalkan → GL
 *   self-inconsistent, butuh cleanup manual accountant.
 *
 * Setelah fix:
 *   JournalEntryObserver::cascadeInvoiceVoid throw RuntimeException kalau
 *   invoice masih punya payment. Admin harus reverse payment dulu.
 */
class CascadeVoidInvoicePaymentGuardTest extends TestCase
{
    public function test_bug03_void_journal_invoice_yang_ada_payment_harus_gagal(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Setup: issue invoice + bayar sebagian
        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        app(InvoiceService::class)->issue($invoice);
        $kas = $this->postableAccount($company, '111100');
        app(PaymentService::class)->pay($invoice->fresh(), $kas, 400_000);

        $invoice->refresh();
        $this->assertSame('sebagian', $invoice->status);
        $this->assertGreaterThan(0, $invoice->payments()->count());

        // Admin coba void jurnal invoice via JournalService (bukan lewat InvoiceService::void)
        $journal = JournalEntry::withoutGlobalScopes()->find($invoice->journal_entry_id);
        $this->assertTrue($journal->isPosted());

        // Setelah fix: cascade guard throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('masih memiliki');

        app(JournalService::class)->void($journal, 'Test admin void invoice yang sudah dibayar');
    }

    public function test_bug03_gl_tidak_corrupt_setelah_void_ditolak(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        app(InvoiceService::class)->issue($invoice);
        $kas = $this->postableAccount($company, '111100');
        app(PaymentService::class)->pay($invoice->fresh(), $kas, 500_000);

        $journalId = $invoice->fresh()->journal_entry_id;
        $journal = JournalEntry::withoutGlobalScopes()->find($journalId);

        // Coba void, harus throw
        try {
            app(JournalService::class)->void($journal, 'test');
            $this->fail('Void seharusnya throw untuk invoice ada payment');
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Verifikasi state: journal tetap posted, invoice tetap sebagian
        $journal->refresh();
        $invoice->refresh();
        $this->assertTrue($journal->isPosted(), 'Journal invoice HARUS tetap posted setelah cascade guard reject');
        $this->assertSame('sebagian', $invoice->status);
        $this->assertSame(500_000.0, (float) $invoice->paid_amount);
    }

    public function test_bug03_void_journal_invoice_belum_ada_payment_tetap_boleh(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Invoice issue tanpa payment
        $invoice = $this->makeDraftInvoice($company, ['amount' => 800_000]);
        app(InvoiceService::class)->issue($invoice);

        $journal = JournalEntry::withoutGlobalScopes()->find($invoice->fresh()->journal_entry_id);
        $this->assertSame(0, $invoice->fresh()->payments()->count());

        // Void via JournalService — tidak throw karena tidak ada payment
        $voided = app(JournalService::class)->void($journal, 'test');
        $this->assertTrue($voided->isVoid());

        // Invoice ikut cascade void
        $invoice->refresh();
        $this->assertSame('void', $invoice->status);
    }

    public function test_bug03_workflow_yang_benar_reverse_payment_dulu_baru_void(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        app(InvoiceService::class)->issue($invoice);
        $kas     = $this->postableAccount($company, '111100');
        $payment = app(PaymentService::class)->pay($invoice->fresh(), $kas, 500_000);

        // Step 1: Reverse payment dulu (yang benar)
        app(PaymentService::class)->reverse($payment, 'Test — batal payment sebelum void invoice');
        $invoice->refresh();
        $this->assertSame('terbit', $invoice->status);
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertSame(0, $invoice->payments()->count());

        // Step 2: Sekarang boleh void jurnal invoice
        $journal = JournalEntry::withoutGlobalScopes()->find($invoice->journal_entry_id);
        $voided = app(JournalService::class)->void($journal, 'Test — void setelah payment di-reverse');
        $this->assertTrue($voided->isVoid());

        $invoice->refresh();
        $this->assertSame('void', $invoice->status);
    }
}
