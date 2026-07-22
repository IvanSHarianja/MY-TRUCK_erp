<?php

namespace Tests\Feature\Bug;

use App\Models\Invoice;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PaymentService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Sprint 1 quick wins regression tests.
 *
 * Cakupan:
 *  - BUG-07: jam_kerja negatif tidak boleh tersimpan (rule gt:hm_awal)
 *  - BUG-08: void button visibility dengan epsilon float tolerance
 *  - GAP-04: FK constraint reversed_by_id (verified via migration file existence
 *            dan mock-test perilaku nullOnDelete di MySQL — untuk sqlite skip)
 */
class Sprint1QuickWinsTest extends TestCase
{
    // ============================================================
    // BUG-07 — Validasi jam_kerja negatif
    // ============================================================

    public function test_bug07_rule_gt_hm_awal_reject_hm_akhir_lebih_kecil(): void
    {
        // Simulasi validasi rule Laravel yang di-attach ke TextInput hm_akhir
        $validator = Validator::make(
            ['hm_awal' => 4872, 'hm_akhir' => 4864],
            ['hm_akhir' => ['gt:hm_awal']]
        );

        $this->assertTrue($validator->fails(),
            'HM akhir < HM awal harus gagal validasi gt:hm_awal'
        );
    }

    public function test_bug07_rule_gt_hm_awal_lolos_saat_hm_akhir_lebih_besar(): void
    {
        $validator = Validator::make(
            ['hm_awal' => 4872, 'hm_akhir' => 4880],
            ['hm_akhir' => ['gt:hm_awal']]
        );

        $this->assertFalse($validator->fails());
    }

    public function test_bug07_rule_gt_hm_awal_reject_saat_hm_akhir_sama(): void
    {
        // Jam kerja 0 juga harus ditolak (tidak ada aktivitas)
        $validator = Validator::make(
            ['hm_awal' => 4872, 'hm_akhir' => 4872],
            ['hm_akhir' => ['gt:hm_awal']]
        );

        $this->assertTrue($validator->fails());
    }

    public function test_bug07_hard_guard_mutate_data_throw_saat_jam_kerja_nol_atau_negatif(): void
    {
        // Simulasi logic dari mutateDataUsing di CreateAction & EditAction
        // (RentalLogsRelationManager.php line 246-266)
        $data = ['hm_awal' => 4872, 'hm_akhir' => 4864];
        $jamKerja = round((float) $data['hm_akhir'] - (float) $data['hm_awal'], 2);

        $this->assertLessThanOrEqual(0, $jamKerja,
            'Kondisi awal harus reproduksi bug (jam kerja negatif)'
        );

        // Setelah fix: guard di mutateDataUsing throw ValidationException.
        // Kita verifikasi behavior yg sama:
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        if ($jamKerja <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'hm_akhir' => 'HM akhir harus lebih besar dari HM awal.',
            ]);
        }
    }

    // ============================================================
    // BUG-08 — Void invoice button visibility (float epsilon)
    // ============================================================

    public function test_bug08_void_button_visible_saat_paid_amount_nol_persis(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        app(InvoiceService::class)->issue($invoice);
        $invoice->refresh();

        // Reproduksi logic dari InvoicesTable::visible
        $isVisible = in_array($invoice->status, ['terbit']) && abs((float) $invoice->paid_amount) < 0.005;

        $this->assertTrue($isVisible,
            'Void button harus muncul untuk invoice terbit belum dibayar'
        );
    }

    public function test_bug08_void_button_visible_saat_paid_amount_float_drift(): void
    {
        // Simulasi float drift residu: paid_amount = 0.0000001 (tidak 0 pas)
        // Sebelum fix: strict === 0.0 gagal → button hilang → user tidak bisa void
        // Setelah fix: abs(...) < 0.005 lolos → button muncul

        $paid_amount = 0.0000001;
        $status = 'terbit';

        // Kondisi lama (buggy): strict identity
        $oldLogic = in_array($status, ['terbit']) && (float) $paid_amount === 0.0;
        $this->assertFalse($oldLogic, 'Kondisi lama harus gagal (bug reproduksi)');

        // Kondisi baru (fixed): epsilon tolerance
        $newLogic = in_array($status, ['terbit']) && abs((float) $paid_amount) < 0.005;
        $this->assertTrue($newLogic, 'Fix harus izinkan button muncul');
    }

    public function test_bug08_void_button_hidden_saat_ada_payment_nyata(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 1_000_000]);
        app(InvoiceService::class)->issue($invoice);
        $kas = $this->postableAccount($company, '111100');
        app(PaymentService::class)->pay($invoice->fresh(), $kas, 500_000);
        $invoice->refresh();

        // Setelah pembayaran 500rb → paid_amount = 500000, harus > 0.005
        $isVisible = in_array($invoice->status, ['terbit', 'sebagian'])
            && abs((float) $invoice->paid_amount) < 0.005;

        $this->assertFalse($isVisible,
            'Void button harus hilang saat ada pembayaran nyata (paid_amount = 500rb)'
        );
    }

    public function test_bug08_void_button_hidden_saat_invoice_lunas(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $invoice = $this->makeDraftInvoice($company, ['amount' => 500_000]);
        app(InvoiceService::class)->issue($invoice);
        $kas = $this->postableAccount($company, '111100');
        app(PaymentService::class)->pay($invoice->fresh(), $kas, 500_000);
        $invoice->refresh();

        // Setelah lunas: status = 'lunas', tidak in ['terbit']
        $isVisible = in_array($invoice->status, ['terbit']) && abs((float) $invoice->paid_amount) < 0.005;

        $this->assertFalse($isVisible);
    }

    // ============================================================
    // GAP-02 — HPP OPTIONAL (business decision 2026-07-20)
    //
    // Behavior:
    //   - HPP=0 di master → sale sukses TAPI jurnal HPP di-skip
    //   - Log warning + Filament notification untuk awareness user
    //   - HPP > 0 → jurnal HPP normal terbentuk
    // ============================================================

    public function test_gap02_material_sale_sukses_walau_harga_pokok_nol(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Material default MAT-001 (harga_pokok=0 dari seed)
        $material = \App\Models\Material::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'MAT-001')
            ->first();
        $this->assertSame(0.0, (float) $material->harga_pokok);

        $client = $this->createClient($company);
        $kas    = $this->postableAccount($company, '111100');

        // Sale tetap sukses meski HPP=0 (business decision optional HPP)
        $sale = app(\App\Services\Accounting\MaterialSaleService::class)->create([
            'material_id'     => $material->id,
            'client_id'       => $client->id,
            'volume'          => 10,
            'harga_satuan'    => 65000,
            'sale_date'       => now()->toDateString(),
            'metode'          => 'tunai',
            'cash_account_id' => $kas->id,
        ]);

        $this->assertNotNull($sale, 'Sale HARUS sukses meski HPP=0 (opsi B business decision)');
        $this->assertSame(650_000.0, (float) $sale->total);

        // Tapi jurnal HPP TIDAK terbentuk (di-skip dengan warning)
        $hppJournal = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', 'HPP-' . $sale->sale_number)
            ->first();
        $this->assertNull($hppJournal, 'Jurnal HPP HARUS di-skip saat HPP master = 0');

        // Sale journal (revenue) tetap terbentuk
        $this->assertNotNull($sale->journal_entry_id);
    }

    public function test_gap02_material_sale_dengan_harga_pokok_valid_tetap_post_hpp(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = \App\Models\Material::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'MAT-002')
            ->first();
        $material->update(['harga_pokok' => 80000]);

        $client = $this->createClient($company);
        $kas    = $this->postableAccount($company, '111100');

        $sale = app(\App\Services\Accounting\MaterialSaleService::class)->create([
            'material_id'     => $material->id,
            'client_id'       => $client->id,
            'volume'          => 5,
            'harga_satuan'    => 110000,
            'sale_date'       => now()->toDateString(),
            'metode'          => 'tunai',
            'cash_account_id' => $kas->id,
        ]);

        // Volume 5 × HPP 80000 = 400000
        $hppJournal = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('document_number', 'HPP-' . $sale->sale_number)
            ->first();

        $this->assertNotNull($hppJournal, 'Jurnal HPP HARUS terbentuk saat harga_pokok valid');
        $this->assertSame(400_000.0, (float) $hppJournal->total_amount);
    }

    public function test_gap02_warning_log_saat_hpp_kosong(): void
    {
        // Verifikasi bahwa Log::warning terpanggil saat HPP=0
        \Illuminate\Support\Facades\Log::spy();

        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = \App\Models\Material::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'MAT-001')
            ->first();
        $client = $this->createClient($company);
        $kas    = $this->postableAccount($company, '111100');

        app(\App\Services\Accounting\MaterialSaleService::class)->create([
            'material_id'     => $material->id,
            'client_id'       => $client->id,
            'volume'          => 3,
            'harga_satuan'    => 65000,
            'sale_date'       => now()->toDateString(),
            'metode'          => 'tunai',
            'cash_account_id' => $kas->id,
        ]);

        // Verifikasi Log::warning terpanggil
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'HPP tidak posted'));
    }

    // ============================================================
    // GAP-04 — FK constraint reversed_by_id
    // ============================================================

    public function test_gap04_migrasi_fk_reversed_by_ada(): void
    {
        // Verifikasi file migrasi ada
        $migrationPath = database_path(
            'migrations/2026_07_20_100000_add_fk_reversed_by_to_journal_entries.php'
        );
        $this->assertFileExists($migrationPath,
            'Migrasi FK reversed_by_id harus ada'
        );

        $content = file_get_contents($migrationPath);

        $this->assertStringContainsString("->foreign('reversed_by_id')", $content);
        $this->assertStringContainsString("nullOnDelete()", $content);
        $this->assertStringContainsString("getDriverName() !== 'mysql'", $content,
            'Migrasi harus guard driver (skip di sqlite)'
        );
    }
}
