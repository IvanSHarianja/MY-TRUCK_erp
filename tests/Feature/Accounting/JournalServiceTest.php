<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TST-02 — Feature test JournalService.
 *
 * Cakupan:
 *  - createEntryWithLines: happy path + auto entry_number
 *  - validateBalance: unbalanced / kurang dari 2 baris / debit+kredit sekaligus / kosong
 *  - post(): draft → posted, guard non-draft, guard akun HEADER
 *  - void(): posted → void + pembalik balanced, guard non-posted
 *
 * Yang TIDAK di-test di sini (butuh test terpisah / mock DB driver):
 *  - Retry pattern pada UniqueConstraintViolation di createEntryWithLines
 *  - assertPeriodOpen (butuh AccountingPeriod row) — covered di TrialBalanceTest
 */
class JournalServiceTest extends TestCase
{
    private JournalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(JournalService::class);
    }

    // ============================================================
    // createEntryWithLines
    // ============================================================

    public function test_create_entry_balanced_sukses_dan_persist_ke_db(): void
    {
        $company = $this->createTenant();
        $kas     = $this->postableAccount($company, '111100');
        $modal   = $this->postableAccount($company, '331100');

        $entry = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 1_000_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,          'kredit' => 1_000_000],
        ]);

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertSame($company->id, $entry->company_id);
        $this->assertCount(2, $entry->lines);
        $this->assertTrue($entry->isBalanced(), 'Entry harus balanced');
        $this->assertSame(1_000_000.0, $entry->total_debit);
        $this->assertSame(1_000_000.0, $entry->total_kredit);
    }

    public function test_entry_number_format_J_YYMM_NNN(): void
    {
        $company = $this->createTenant();
        $date    = Carbon::create(2026, 8, 15);

        $entryNumber = $this->service->generateEntryNumber($company, $date);

        $this->assertSame('J2608-001', $entryNumber);
    }

    public function test_entry_number_kedua_ter_increment(): void
    {
        $company = $this->createTenant();
        $kas     = $this->postableAccount($company, '111100');
        $modal   = $this->postableAccount($company, '331100');
        $date    = Carbon::create(2026, 8, 15);

        $entry1 = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 500_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,        'kredit' => 500_000],
        ], date: $date);

        $entry2 = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 300_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,        'kredit' => 300_000],
        ], date: $date);

        $this->assertSame('J2608-001', $entry1->entry_number);
        $this->assertSame('J2608-002', $entry2->entry_number);
    }

    public function test_dua_company_generate_nomor_independen(): void
    {
        $companyA = $this->createTenant(['name' => 'PT Alpha']);
        $companyB = $this->createTenant(['name' => 'PT Beta']);
        $date     = Carbon::create(2026, 8, 15);

        $kasA   = $this->postableAccount($companyA, '111100');
        $modalA = $this->postableAccount($companyA, '331100');
        $kasB   = $this->postableAccount($companyB, '111100');
        $modalB = $this->postableAccount($companyB, '331100');

        $entryA = $this->makeJournalEntry($companyA, [
            ['account_id' => $kasA->id,   'debit' => 100_000, 'kredit' => 0],
            ['account_id' => $modalA->id, 'debit' => 0,        'kredit' => 100_000],
        ], date: $date);

        $entryB = $this->makeJournalEntry($companyB, [
            ['account_id' => $kasB->id,   'debit' => 200_000, 'kredit' => 0],
            ['account_id' => $modalB->id, 'debit' => 0,        'kredit' => 200_000],
        ], date: $date);

        // Kedua company boleh punya J2608-001 karena unique-nya per company
        $this->assertSame('J2608-001', $entryA->entry_number);
        $this->assertSame('J2608-001', $entryB->entry_number);
    }

    // ============================================================
    // validateBalance
    // ============================================================

    public function test_validate_balance_throw_saat_debit_kredit_tidak_sama(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak balance');

        $this->service->validateBalance([
            ['debit' => 100_000, 'kredit' => 0],
            ['debit' => 0,        'kredit' => 99_999],
        ]);
    }

    public function test_validate_balance_throw_saat_line_kurang_dari_2(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('minimal harus punya 2 baris');

        $this->service->validateBalance([
            ['debit' => 100_000, 'kredit' => 0],
        ]);
    }

    public function test_validate_balance_throw_saat_baris_ada_debit_dan_kredit_sekaligus(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak boleh berisi Debit dan Kredit sekaligus');

        $this->service->validateBalance([
            ['debit' => 100_000, 'kredit' => 100_000],
            ['debit' => 0,        'kredit' => 100_000],
        ]);
    }

    public function test_validate_balance_throw_saat_baris_kosong_semua(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('harus berisi salah satu: Debit atau Kredit');

        $this->service->validateBalance([
            ['debit' => 0, 'kredit' => 0],
            ['debit' => 100_000, 'kredit' => 0],
        ]);
    }

    public function test_validate_balance_lolos_saat_toleransi_pembulatan_2_desimal(): void
    {
        // Tidak throw — total sama saat di-round ke 2 desimal
        $this->service->validateBalance([
            ['debit' => 1234.567, 'kredit' => 0],
            ['debit' => 0, 'kredit' => 1234.568], // beda di digit ke-3
        ]);

        $this->assertTrue(true, 'Balance harus lolos untuk selisih < 0.005');
    }

    // ============================================================
    // post() — draft → posted
    // ============================================================

    public function test_post_draft_ke_posted_update_status_dan_posted_at(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $kas   = $this->postableAccount($company, '111100');
        $modal = $this->postableAccount($company, '331100');

        $entry = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 500_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,        'kredit' => 500_000],
        ]);
        $this->assertTrue($entry->isDraft());

        $posted = $this->service->post($entry);

        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->posted_at);
        $this->assertSame($user->id, $posted->posted_by);
        $this->assertSame(500_000.0, (float) $posted->total_amount);
    }

    public function test_post_non_draft_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $kas   = $this->postableAccount($company, '111100');
        $modal = $this->postableAccount($company, '331100');

        $entry = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 100_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,        'kredit' => 100_000],
        ]);
        $this->service->post($entry);
        $entry->refresh();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Hanya jurnal status DRAFT');
        $this->service->post($entry);
    }

    public function test_post_ke_akun_header_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Ubah akun 111100 jadi HEADER dengan bikin child manual
        Account::withoutGlobalScopes()->create([
            'company_id'         => $company->id,
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

        // Ambil 111100 langsung (bukan via findPostableByCode karena itu fallback ke child)
        $header = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '111100')
            ->first();
        $modal  = $this->postableAccount($company, '331100');

        $entry = $this->makeJournalEntry($company, [
            ['account_id' => $header->id, 'debit' => 100_000, 'kredit' => 0],
            ['account_id' => $modal->id,  'debit' => 0,        'kredit' => 100_000],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('HEADER');
        $this->service->post($entry);
    }

    // ============================================================
    // void() — posted → void + jurnal pembalik
    // ============================================================

    public function test_void_posted_bikin_pembalik_balanced_dan_flip_debit_kredit(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $kas   = $this->postableAccount($company, '111100');
        $modal = $this->postableAccount($company, '331100');

        $entry = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 750_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,        'kredit' => 750_000],
        ]);
        $this->service->post($entry);
        $entry->refresh();

        $voided = $this->service->void($entry, reason: 'Test rollback');

        $this->assertTrue($voided->isVoid());
        $this->assertNotNull($voided->reversed_by_id);

        // Ambil jurnal pembalik & verifikasi
        $reverse = JournalEntry::findOrFail($voided->reversed_by_id);

        $this->assertTrue($reverse->isPosted());
        $this->assertSame('pembalik', $reverse->document_type);
        $this->assertStringContainsString('REV-' . $entry->entry_number, $reverse->document_number);
        $this->assertTrue($reverse->isBalanced());
        $this->assertSame(750_000.0, $reverse->total_debit);

        // Verifikasi debit/kredit dibalik
        $originalKasLine     = $entry->lines->where('account_id', $kas->id)->first();
        $reverseKasLine      = $reverse->lines->where('account_id', $kas->id)->first();
        $this->assertSame((float) $originalKasLine->debit,  (float) $reverseKasLine->kredit);
        $this->assertSame((float) $originalKasLine->kredit, (float) $reverseKasLine->debit);
    }

    public function test_void_non_posted_throw(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $kas   = $this->postableAccount($company, '111100');
        $modal = $this->postableAccount($company, '331100');

        $entry = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 100_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,        'kredit' => 100_000],
        ]);

        // Belum di-post, masih draft
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Hanya jurnal status POSTED');
        $this->service->void($entry);
    }

    public function test_gl_setelah_post_dan_void_net_nol(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $kas   = $this->postableAccount($company, '111100');
        $modal = $this->postableAccount($company, '331100');

        $entry = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 1_500_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,          'kredit' => 1_500_000],
        ]);
        $this->service->post($entry);
        $this->service->void($entry->fresh(), reason: 'test');

        // Setelah void: kombinasi entry asli (void) + pembalik = 0 impact di GL
        // Kita cek TOTAL debit/kredit dari semua POSTED entries di company ini
        $postedDebit = \App\Models\JournalEntryLine::whereIn('journal_entry_id',
            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'posted')
                ->pluck('id')
        )->sum('debit');

        $postedKredit = \App\Models\JournalEntryLine::whereIn('journal_entry_id',
            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'posted')
                ->pluck('id')
        )->sum('kredit');

        // Hanya pembalik yang berstatus 'posted' (asli sudah void)
        // Debit dan kredit pembalik = 1_500_000 masing-masing
        $this->assertSame(1_500_000.0, (float) $postedDebit);
        $this->assertSame(1_500_000.0, (float) $postedKredit);
        $this->assertSame((float) $postedDebit, (float) $postedKredit,
            'Trial balance harus tetap balanced setelah void');
    }
}
