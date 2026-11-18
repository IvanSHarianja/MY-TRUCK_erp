<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectAddendum;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\DebtService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\ProjectAddendumService;
use App\Services\Accounting\ProjectMarginService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Masukan 2026-11-18: tanda tangan & cap invoice, transfer antar rekening
 * dengan approval Owner, pekerjaan tambah proyek, hutang & cicilan.
 */
class OwnerApprovalFinanceTest extends TestCase
{
    private Company $company;
    private User $owner;
    private User $admin;
    private Account $bca;
    private Account $mandiri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner = $this->createTenantUser($this->company);
        $this->admin = $this->createTenantUser($this->company, [], 'admin');
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');

        $mk = fn (string $code, string $name) => Account::create([
            'company_id' => $this->company->id, 'code' => $code, 'parent_code' => '111100',
            'name' => $name, 'category' => 'aset', 'normal_balance' => 'debit', 'role' => 'cash', 'is_active' => true,
        ]);
        $this->bca = $mk('111100-01', 'Bank BCA');
        $this->mandiri = $mk('111100-02', 'Bank Mandiri');
    }

    private function balance(int $accountId): float
    {
        return (float) DB::table('journal_entry_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company->id)->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->where('jl.account_id', $accountId)->sum(DB::raw('jl.debit - jl.kredit'));
    }

    private function balanceByCode(string $code): float
    {
        return $this->balance($this->postableAccount($this->company, $code)->id);
    }

    // ── Tanda tangan & cap ───────────────────────────────────────────

    public function test_invoice_pdf_menampilkan_tanda_tangan_dan_cap_bila_diunggah(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('company-signatures/ttd.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        $this->company->update(['owner_name' => 'Budi Santoso', 'signature_path' => 'company-signatures/ttd.png']);
        $invoice = $this->makeDraftInvoice($this->company, ['status' => 'terbit']);

        $html = view('pdf.invoice', ['invoice' => $invoice, 'company' => $this->company->fresh()])->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Budi Santoso', $html);
        $this->assertStringContainsString('ditandatangani secara elektronik', $html);

        $this->company->update(['signature_path' => null]);
        $plain = view('pdf.invoice', ['invoice' => $invoice, 'company' => $this->company->fresh()])->render();
        $this->assertStringNotContainsString('data:image/png;base64,', $plain);
        $this->assertStringContainsString('sah tanpa tanda tangan basah', $plain);
    }

    public function test_file_bukan_gambar_tidak_dirender(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('company-signatures/x.png', '<?php echo 1;');
        $this->company->update(['signature_path' => 'company-signatures/x.png']);

        $this->assertNull($this->company->fresh()->imageDataUri('signature_path'));
    }

    // ── Transfer antar rekening ──────────────────────────────────────

    public function test_deteksi_jurnal_pindah_kas_dan_daftar_menunggu(): void
    {
        $transfer = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->mandiri->id, 'debit' => 500_000, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 500_000],
        ]);
        $biaya = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->postableAccount($this->company, '552500')->id, 'debit' => 6_500, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 6_500],
        ]);
        $saldoAwal = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->mandiri->id, 'debit' => 1_000, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 1_000],
        ], ['document_type' => 'saldo_awal']);

        $this->assertTrue($transfer->isCashTransfer());
        $this->assertFalse($biaya->isCashTransfer(), 'beban dibayar dari bank → bukan pindah dana');
        $this->assertTrue($saldoAwal->needsOwnerApproval(), 'saldo awal selalu wajib Owner');
        $this->assertFalse($biaya->needsOwnerApproval());
        $this->assertEqualsCanonicalizing([$transfer->id, $saldoAwal->id],
            JournalEntry::pendingOwnerApproval($this->company->id)->pluck('id')->all());

        app(JournalService::class)->post($transfer);
        $this->assertEquals([$saldoAwal->id], JournalEntry::pendingOwnerApproval($this->company->id)->pluck('id')->all(),
            'yang sudah diposting tidak lagi menunggu');
    }

    public function test_jurnal_manual_antar_kas_ditolak_untuk_non_owner(): void
    {
        $lines = [
            ['account_id' => $this->mandiri->id, 'debit' => 100_000, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 100_000],
        ];
        $this->actingAsTenant($this->admin, $this->company);
        $entry = $this->makeJournalEntry($this->company, $lines);

        try {
            app(JournalService::class)->post($entry);
            $this->fail('Jurnal pindah kas oleh admin harus ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('wajib disetujui Owner', collect($e->errors())->flatten()->implode(' '));
        }

        $this->actingAsTenant($this->owner, $this->company);
        app(JournalService::class)->post($entry->fresh());
        $this->assertSame('posted', $entry->fresh()->status);
    }

    // ── Pekerjaan tambah proyek ──────────────────────────────────────

    private function project(): Project
    {
        return Project::create([
            'company_id' => $this->company->id, 'project_number' => 'PR26-0007', 'name' => 'Urug Lahan',
            'client_id' => $this->createClient($this->company)->id, 'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak' => 100_000_000, 'progress_pct' => 0, 'tertagih_pct' => 0, 'dp_diterima' => 0,
            'status' => 'berjalan', 'created_by' => $this->owner->id,
        ]);
    }

    public function test_pekerjaan_tambah_admin_menunggu_owner_lalu_invoice_terbit(): void
    {
        $project = $this->project();
        $svc = app(ProjectAddendumService::class);
        $add = $svc->submit($project, $this->admin, ['tanggal' => now()->toDateString(), 'uraian' => 'Tambahan timbunan 500 m3', 'nilai' => 12_500_000]);

        $this->assertSame('pending', $add->status);
        $this->assertSame('PR26-0007-ADD01', $add->addendum_number);
        $this->assertNull($add->invoice_id);
        $this->assertEquals(0, $this->balanceByCode('111200'));

        $add = $svc->approve($add, $this->owner);
        $invoice = Invoice::find($add->invoice_id);
        $this->assertSame('approved', $add->status);
        $this->assertSame('terbit', $invoice->status);
        $this->assertSame('BONG', $invoice->businessUnit?->code);
        $this->assertEquals(12_500_000, $this->balanceByCode('111200'));
        $this->assertEquals(112_500_000, $project->fresh()->nilaiKontrakTotal());

        $margin = app(ProjectMarginService::class)->report($this->company->id);
        $this->assertEquals(12_500_000, $margin['rows']->first()['pendapatan']);

        // Void invoice → pekerjaan tambah ikut batal dari nilai kontrak
        app(InvoiceService::class)->void($invoice, 'salah input');
        $this->assertSame('void', $add->fresh()->status);
        $this->assertEquals(100_000_000, $project->fresh()->nilaiKontrakTotal());
    }

    public function test_pekerjaan_tambah_owner_langsung_disetujui_admin_tidak_bisa_approve(): void
    {
        $project = $this->project();
        $svc = app(ProjectAddendumService::class);

        $direct = $svc->submit($project, $this->owner, ['uraian' => 'Perataan tambahan', 'nilai' => 2_000_000]);
        $this->assertSame('approved', $direct->status);
        $this->assertNotNull($direct->invoice_id);

        $pending = $svc->submit($project, $this->admin, ['uraian' => 'Drainase tambahan', 'nilai' => 3_000_000]);
        $this->expectException(ValidationException::class);
        $svc->approve($pending, $this->admin);
    }

    // ── Hutang & cicilan ─────────────────────────────────────────────

    public function test_pinjaman_bank_jadwal_cicilan_dan_bayar(): void
    {
        $utangBank = $this->postableAccount($this->company, '222110');
        $svc = app(DebtService::class);
        $debt = $svc->create($this->company, $this->owner, [
            'jenis' => 'bank', 'kreditur' => 'Bank BRI', 'payable_account_id' => $utangBank->id,
            'pokok' => 120_000_005, 'bunga_pct' => 12, 'tenor_bulan' => 12,
            'tanggal_mulai' => '2026-11-01', 'jatuh_tempo_pertama' => '2026-12-31',
            'disbursement_account_id' => $this->bca->id,
        ]);

        $rows = $debt->installments;
        $this->assertCount(12, $rows);
        $this->assertEquals(120_000_005, $rows->sum('pokok'), 'sisa pembulatan di cicilan terakhir');
        $this->assertEquals(10_000_000, $rows->first()->pokok);
        $this->assertEquals(10_000_005, $rows->last()->pokok);
        $this->assertEquals(1_200_000, $rows->first()->bunga);   // 1% per bulan, flat
        $this->assertSame('2027-02-28', $rows[2]->due_date->toDateString(), 'tanggal 31 tidak loncat bulan');
        $this->assertEquals(120_000_005, $this->balance($this->bca->id));
        $this->assertEquals(-120_000_005, $this->balance($utangBank->id));

        $first = $rows->first();
        $paid = $svc->pay($first, $this->owner, ['paid_date' => '2026-12-30', 'cash_account_id' => $this->bca->id, 'bunga' => 1_200_000, 'denda' => 50_000]);
        $this->assertTrue($paid->isPaid());
        $this->assertEquals(-110_000_005, $this->balance($utangBank->id));
        $this->assertEquals(1_250_000, $this->balanceByCode('552700'));
        $this->assertEquals(120_000_005 - 11_250_000, $this->balance($this->bca->id));
        $this->assertEquals(110_000_005, $svc->outstanding($debt));

        $this->expectException(ValidationException::class);
        $svc->pay($first->fresh(), $this->owner, ['paid_date' => '2026-12-30', 'cash_account_id' => $this->bca->id]);
    }

    public function test_batal_bayar_mengembalikan_saldo_dan_lunas_bila_semua_dibayar(): void
    {
        $utangLeasing = $this->postableAccount($this->company, '222100');
        $svc = app(DebtService::class);
        $debt = $svc->create($this->company, $this->owner, [
            'jenis' => 'leasing', 'kreditur' => 'Mandiri Tunas', 'payable_account_id' => $utangLeasing->id,
            'pokok' => 2_000_000, 'tenor_bulan' => 2, 'tanggal_mulai' => now()->toDateString(),
            'jatuh_tempo_pertama' => now()->addMonth()->toDateString(),
        ]);
        $this->assertNull($debt->disbursement_journal_id, 'hutang lama tanpa pencairan → tanpa jurnal');

        [$a, $b] = $debt->installments->all();
        $svc->pay($a, $this->owner, ['paid_date' => now()->toDateString(), 'cash_account_id' => $this->bca->id]);
        $svc->pay($b, $this->owner, ['paid_date' => now()->toDateString(), 'cash_account_id' => $this->bca->id]);
        $this->assertSame('lunas', $debt->fresh()->status);
        $this->assertEquals(2_000_000, $this->balance($utangLeasing->id));

        $svc->cancelPayment($b->fresh(), $this->owner);
        $this->assertSame('aktif', $debt->fresh()->status);
        $this->assertEquals(1_000_000, $this->balance($utangLeasing->id));
        $this->assertEquals(-1_000_000, $this->balance($this->bca->id));

        try {
            $svc->delete($debt->fresh());
            $this->fail('Tidak bisa hapus bila ada cicilan dibayar');
        } catch (ValidationException) {
        }
    }

    public function test_pembelian_material_kredit_masuk_hutang_vendor_dengan_jatuh_tempo(): void
    {
        $vendor = Vendor::create(['company_id' => $this->company->id, 'code' => 'V-01', 'name' => 'CV Batu Jaya', 'is_active' => true]);
        $material = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

        $purchase = app(MaterialPurchaseService::class)->record([
            'material_id' => $material->id, 'vendor_id' => $vendor->id, 'purchase_date' => now()->toDateString(),
            'qty' => 10, 'unit_price' => 150_000, 'payment_method' => 'kredit', 'due_date' => now()->addDays(14)->toDateString(),
        ]);

        $debt = Debt::where('source_id', $purchase->id)->firstOrFail();
        $this->assertSame('vendor', $debt->jenis);
        $this->assertSame('CV Batu Jaya', $debt->kreditur);
        $this->assertEquals(1_500_000, $debt->pokok);
        $inst = $debt->installments->sole();
        $this->assertSame(now()->addDays(14)->toDateString(), $inst->due_date->toDateString());
        $this->assertSame('later', $inst->urgency());

        $utangVendor = $debt->payable_account_id;
        $this->assertEquals(-1_500_000, $this->balance($utangVendor), 'hutang dari jurnal pembelian, tidak dobel');

        app(DebtService::class)->pay($inst, $this->owner, ['paid_date' => now()->toDateString(), 'cash_account_id' => $this->bca->id]);
        $this->assertEquals(0, $this->balance($utangVendor));
        $this->assertSame('lunas', $debt->fresh()->status);
    }

    public function test_urgensi_jatuh_tempo(): void
    {
        $i = new DebtInstallment(['due_date' => today()->subDays(3), 'status' => 'belum']);
        $this->assertSame('overdue', $i->urgency());
        $this->assertSame('Telat 3 hari', $i->dueLabel());
        $i->due_date = today()->addDays(5);
        $this->assertSame('soon', $i->urgency());
        $i->due_date = today()->addDays(20);
        $this->assertSame('later', $i->urgency());
    }
}
