<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Services\Accounting\InvoiceService;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * TST-05 — Feature test Multi-tenant isolation.
 *
 * Verifikasi: Company A TIDAK bisa akses / query / manipulasi data Company B
 * via Eloquent scope, meski keduanya bergaya multi-tenant di 1 database.
 *
 * Cakupan:
 *  - Global scope BelongsToCompany filter query saat Filament::setTenant(A) aktif
 *  - findPostableByCode() explicit scope by company_id — safe by design
 *  - Data operasional (Invoice, Client) tidak leak antar company
 *  - `withoutGlobalScopes` boleh (backend needs), tapi query eksplisit tetap terpisah
 *
 * TIDAK di-test di sini (masuk Sprint 1 saat fix BUG-01 & BUG-02):
 *  - Route PDF /pdf/invoice/{id}: cross-tenant leak
 *  - Route PDF /pdf/{tenant:slug}/*: cross-tenant leak
 *  Test route PDF akan ditulis sebagai regression AT fix-time.
 */
class TenantIsolationTest extends TestCase
{
    // ============================================================
    // Global scope aktif saat Filament::setTenant
    // ============================================================

    public function test_global_scope_filter_query_ke_tenant_aktif(): void
    {
        $companyA = $this->createTenant(['name' => 'PT Alpha']);
        $companyB = $this->createTenant(['name' => 'PT Beta']);

        Filament::setTenant($companyA, isQuiet: true);

        // Query Account tanpa filter explicit — harusnya hanya lihat company A
        $count = Account::count();
        $this->assertSame(53, $count, 'Global scope harus filter ke tenant aktif');
    }

    public function test_global_scope_switch_saat_tenant_berubah(): void
    {
        $companyA = $this->createTenant(['name' => 'PT Alpha']);
        $companyB = $this->createTenant(['name' => 'PT Beta']);

        Filament::setTenant($companyA, isQuiet: true);
        $this->assertSame(53, Account::count());

        Filament::setTenant($companyB, isQuiet: true);
        $this->assertSame(53, Account::count());

        // Semua account IDs milik B, TIDAK ada yang milik A
        $idsB = Account::pluck('id')->all();
        $idsA = Account::withoutGlobalScopes()
            ->where('company_id', $companyA->id)
            ->pluck('id')
            ->all();

        $this->assertEmpty(array_intersect($idsA, $idsB),
            'ID set A dan B tidak boleh saling tumpang tindih'
        );
    }

    public function test_business_unit_terisolasi_per_company(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();

        // BusinessUnit RENT ada di kedua company, tapi ID-nya berbeda
        $rentA = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $companyA->id)
            ->where('code', 'RENT')
            ->first();

        $rentB = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $companyB->id)
            ->where('code', 'RENT')
            ->first();

        $this->assertNotNull($rentA);
        $this->assertNotNull($rentB);
        $this->assertNotSame($rentA->id, $rentB->id);
    }

    // ============================================================
    // findPostableByCode: safe by design (explicit company_id param)
    // ============================================================

    public function test_find_postable_by_code_explicit_scope_by_company(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();

        $kasA = Account::findPostableByCode('111100', $companyA->id);
        $kasB = Account::findPostableByCode('111100', $companyB->id);

        $this->assertNotNull($kasA);
        $this->assertNotNull($kasB);
        $this->assertNotSame($kasA->id, $kasB->id);
        $this->assertSame($companyA->id, $kasA->company_id);
        $this->assertSame($companyB->id, $kasB->company_id);
    }

    // ============================================================
    // Data operasional (Invoice, Client) tidak leak
    // ============================================================

    public function test_invoice_dan_client_terisolasi_per_company(): void
    {
        $companyA = $this->createTenant(['name' => 'PT Alpha']);
        $userA    = $this->createTenantUser($companyA);
        $companyB = $this->createTenant(['name' => 'PT Beta']);
        $userB    = $this->createTenantUser($companyB);

        // Login A → bikin invoice A
        $this->actingAs($userA);
        $invoiceA = $this->makeDraftInvoice($companyA, ['amount' => 500_000]);
        app(InvoiceService::class)->issue($invoiceA);

        // Login B → bikin invoice B
        $this->actingAs($userB);
        $invoiceB = $this->makeDraftInvoice($companyB, ['amount' => 700_000]);
        app(InvoiceService::class)->issue($invoiceB);

        // A tidak boleh find invoice B lewat query yang di-scope ke A
        Filament::setTenant($companyA, isQuiet: true);
        $this->assertNull(Invoice::find($invoiceB->id),
            'Company A tidak boleh menemukan invoice B via query scoped'
        );
        $this->assertNotNull(Invoice::find($invoiceA->id));

        // Sebaliknya
        Filament::setTenant($companyB, isQuiet: true);
        $this->assertNull(Invoice::find($invoiceA->id),
            'Company B tidak boleh menemukan invoice A via query scoped'
        );
        $this->assertNotNull(Invoice::find($invoiceB->id));
    }

    public function test_client_terisolasi_walau_code_sama(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();

        $clientA = $this->createClient($companyA, ['code' => 'CLT-DUP', 'name' => 'PT Klien A']);
        $clientB = $this->createClient($companyB, ['code' => 'CLT-DUP', 'name' => 'PT Klien B']);

        // Unique constraint (company_id, code) — boleh code sama antar company
        $this->assertSame($clientA->code, $clientB->code);
        $this->assertNotSame($clientA->id, $clientB->id);

        // Search dari A tidak temukan client B
        Filament::setTenant($companyA, isQuiet: true);
        $found = Client::where('code', 'CLT-DUP')->first();
        $this->assertSame($clientA->id, $found->id);
        $this->assertSame('PT Klien A', $found->name);
    }

    // ============================================================
    // Journal entry terisolasi per company
    // ============================================================

    public function test_journal_entry_terisolasi_per_company(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $companyB = $this->createTenant();
        $userB    = $this->createTenantUser($companyB);

        // Journal di A
        $this->actingAs($userA);
        $kasA   = $this->postableAccount($companyA, '111100');
        $modalA = $this->postableAccount($companyA, '331100');
        $entryA = $this->makeJournalEntry($companyA, [
            ['account_id' => $kasA->id,   'debit' => 100_000, 'kredit' => 0],
            ['account_id' => $modalA->id, 'debit' => 0,        'kredit' => 100_000],
        ]);

        // Journal di B
        $this->actingAs($userB);
        $kasB   = $this->postableAccount($companyB, '111100');
        $modalB = $this->postableAccount($companyB, '331100');
        $entryB = $this->makeJournalEntry($companyB, [
            ['account_id' => $kasB->id,   'debit' => 200_000, 'kredit' => 0],
            ['account_id' => $modalB->id, 'debit' => 0,        'kredit' => 200_000],
        ]);

        // Scope A → hanya lihat entry A
        Filament::setTenant($companyA, isQuiet: true);
        $ids = JournalEntry::pluck('id')->all();
        $this->assertContains($entryA->id, $ids);
        $this->assertNotContains($entryB->id, $ids);
    }

    // ============================================================
    // Materials — data master ikut tenant scope
    // ============================================================

    public function test_material_master_terisolasi_per_company(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();

        Filament::setTenant($companyA, isQuiet: true);
        $countA = Material::count();

        Filament::setTenant($companyB, isQuiet: true);
        $countB = Material::count();

        $this->assertSame(7, $countA, 'Company A punya 7 material default');
        $this->assertSame(7, $countB, 'Company B punya 7 material default');

        // Total di DB = 14, tapi masing-masing tenant hanya lihat 7
        $totalDb = Material::withoutGlobalScopes()->count();
        $this->assertSame(14, $totalDb);
    }

    // ============================================================
    // BelongsToCompany trait: auto-set company_id saat creating tanpa param
    // ============================================================

    public function test_auto_set_company_id_saat_creating_tanpa_explicit_company_id(): void
    {
        $companyA = $this->createTenant();
        Filament::setTenant($companyA, isQuiet: true);

        // Client tanpa company_id → trait auto-fill dari tenant aktif
        $client = Client::create([
            'code'      => 'CLT-AUTO',
            'name'      => 'Auto Fill Test',
            'is_active' => true,
        ]);

        $this->assertSame($companyA->id, $client->company_id);
    }

    // ============================================================
    // canAccessTenant check di User model
    // ============================================================

    public function test_user_hanya_bisa_access_tenant_yang_dia_pivot_terhadap(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();

        $userA = $this->createTenantUser($companyA);
        // userA tidak di-attach ke companyB

        $this->assertTrue($userA->canAccessTenant($companyA));
        $this->assertFalse($userA->canAccessTenant($companyB),
            'User A tidak boleh punya akses ke company B karena tidak ada pivot'
        );
    }

    public function test_user_yang_pivot_inactive_ditolak_access(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);

        // Set pivot is_active = false
        $companyA->users()->updateExistingPivot($userA->id, ['is_active' => false]);

        $this->assertFalse($userA->canAccessTenant($companyA),
            'User dengan pivot is_active=false harus ditolak akses'
        );
    }
}
