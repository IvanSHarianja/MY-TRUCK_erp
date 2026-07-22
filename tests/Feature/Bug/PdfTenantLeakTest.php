<?php

namespace Tests\Feature\Bug;

use App\Services\Accounting\InvoiceService;
use Tests\TestCase;

/**
 * BUG-01 & BUG-02 — Cross-tenant PDF leak regression.
 *
 * Sebelum fix: user Company A cukup tebak angka di /pdf/invoice/{id} atau
 * /pdf/{slug_B}/trial-balance untuk download data Company B.
 *
 * Setelah fix (middleware EnsurePdfTenantAccess): request tsb 403.
 */
class PdfTenantLeakTest extends TestCase
{
    // ============================================================
    // BUG-01 — Cross-tenant invoice PDF
    // ============================================================

    public function test_bug01_user_a_tidak_bisa_download_invoice_pdf_company_b(): void
    {
        $companyA = $this->createTenant(['name' => 'PT Alpha']);
        $userA    = $this->createTenantUser($companyA);

        $companyB = $this->createTenant(['name' => 'PT Beta']);
        $userB    = $this->createTenantUser($companyB);

        // Bikin invoice di Company B
        $this->actingAs($userB);
        $invoiceB = $this->makeDraftInvoice($companyB, ['amount' => 5_000_000]);
        app(InvoiceService::class)->issue($invoiceB);

        // User A coba akses invoice B → HARUS 403
        $this->actingAs($userA);
        $response = $this->get(route('pdf.invoice', ['invoice' => $invoiceB->id]));
        $response->assertStatus(403);
    }

    public function test_bug01_user_bisa_download_invoice_pdf_di_company_sendiri(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $this->actingAs($userA);

        $invoice = $this->makeDraftInvoice($companyA, ['amount' => 1_000_000]);
        app(InvoiceService::class)->issue($invoice);

        $response = $this->get(route('pdf.invoice', ['invoice' => $invoice->id]));
        // 200 (streaming PDF response) — user authorized
        $response->assertStatus(200);
    }

    // Catatan: guest flow tidak dites di sini — itu perilaku middleware `auth`
    // standar Laravel, di luar scope BUG-01. App MY-TRUCK login lewat Filament
    // di /admin/login (bukan route named 'login'), jadi guest ke /pdf/* akan
    // handled Filament's authentication flow via middleware `web`.

    // ============================================================
    // BUG-02 — Cross-tenant financial report PDF (7 laporan)
    // ============================================================

    public function test_bug02_user_a_tidak_bisa_akses_trial_balance_company_b(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $companyB = $this->createTenant();

        $this->actingAs($userA);

        $response = $this->get(route('pdf.trial-balance', ['tenant' => $companyB->slug]));
        $response->assertStatus(403);
    }

    public function test_bug02_user_a_tidak_bisa_akses_7_laporan_company_b(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $companyB = $this->createTenant();

        $this->actingAs($userA);

        $reports = [
            'pdf.trial-balance',
            'pdf.income-statement',
            'pdf.income-statement-matrix',
            'pdf.income-statement-by-asset',
            'pdf.balance-sheet',
            'pdf.equity-statement',
            'pdf.cash-flow',
        ];

        foreach ($reports as $routeName) {
            $response = $this->get(route($routeName, ['tenant' => $companyB->slug]));
            $response->assertStatus(403, "Route {$routeName} harus 403 untuk cross-tenant access");
        }
    }

    public function test_bug02_user_bisa_akses_trial_balance_company_sendiri(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $response = $this->get(route('pdf.trial-balance', ['tenant' => $company->slug]));
        $response->assertStatus(200);
    }

    public function test_bug02_user_dengan_pivot_inactive_ditolak(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);

        // Set pivot is_active = false
        $company->users()->updateExistingPivot($user->id, ['is_active' => false]);

        $this->actingAs($user);

        $response = $this->get(route('pdf.trial-balance', ['tenant' => $company->slug]));
        $response->assertStatus(403,
            'User dengan pivot is_active=false harus ditolak akses PDF'
        );
    }
}
