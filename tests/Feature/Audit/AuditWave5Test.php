<?php

namespace Tests\Feature\Audit;

use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Debt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectAddendum;
use App\Models\ProjectTermin;
use App\Models\User;
use App\Services\Accounting\DebtService;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use App\Services\Accounting\ProjectAddendumService;
use App\Services\Accounting\ProjectService;
use App\Support\SignatureImageCleaner;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Audit ulang 2026-11-18 — gelombang 2 (prioritas sedang). */
class AuditWave5Test extends TestCase
{
    private Company $company;
    private User $owner;
    private User $admin;
    private Account $bca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner = $this->createTenantUser($this->company);
        $this->admin = $this->createTenantUser($this->company, [], 'admin');
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');
        $this->bca = Account::create([
            'company_id' => $this->company->id, 'code' => '111100-01', 'parent_code' => '111100',
            'name' => 'Bank BCA', 'category' => 'aset', 'normal_balance' => 'debit', 'role' => 'cash', 'is_active' => true,
        ]);
    }

    private function project(array $attrs = []): Project
    {
        return Project::create($attrs + [
            'company_id' => $this->company->id, 'project_number' => 'PR26-0001', 'name' => 'Urug',
            'client_id' => $this->createClient($this->company)->id, 'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak' => 100_000_000, 'progress_pct' => 100, 'tertagih_pct' => 0, 'dp_diterima' => 0,
            'status' => 'berjalan', 'created_by' => $this->owner->id,
        ]);
    }

    // ── 7. PDF invoice draft / void ──────────────────────────────────────

    public function test_pdf_invoice_draft_dan_void_tanpa_tanda_tangan_dengan_watermark(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('company-signatures/ttd.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        $this->company->update(['signature_path' => 'company-signatures/ttd.png']);

        foreach (['draft' => 'masih DRAFT', 'void' => 'DIBATALKAN'] as $status => $text) {
            $invoice = $this->makeDraftInvoice($this->company, ['status' => $status]);
            $html = view('pdf.invoice', ['invoice' => $invoice, 'company' => $this->company->fresh()])->render();

            $this->assertStringNotContainsString('data:image/png;base64,', $html, "{$status}: tanpa tanda tangan");
            $this->assertStringContainsString('class="watermark"', $html);
            $this->assertStringContainsString($text, $html);
        }
    }

    // ── 8. Pembersih tanda tangan ────────────────────────────────────────

    public function test_gambar_sangat_tinggi_diperkecil_tanpa_kehabisan_memori(): void
    {
        $im = imagecreatetruecolor(600, 6000);
        imagefill($im, 0, 0, imagecolorallocate($im, 235, 235, 225));
        imagefilledrectangle($im, 200, 100, 400, 5800, imagecolorallocate($im, 40, 30, 30));
        ob_start();
        imagepng($im);

        $out = imagecreatefromstring(SignatureImageCleaner::clean(ob_get_clean()));
        $this->assertLessThanOrEqual(800, imagesy($out));
    }

    // ── 9. Jurnal hutang tidak bisa di-void dari Jurnal Umum ─────────────

    public function test_jurnal_bayar_cicilan_tidak_bisa_divoid_dari_jurnal_umum(): void
    {
        $svc = app(DebtService::class);
        $debt = $svc->create($this->company, $this->owner, [
            'jenis' => 'bank', 'kreditur' => 'BRI', 'payable_account_id' => $this->postableAccount($this->company, '222110')->id,
            'pokok' => 2_000_000, 'tenor_bulan' => 2, 'tanggal_mulai' => now()->toDateString(),
            'jatuh_tempo_pertama' => now()->toDateString(), 'disbursement_account_id' => $this->bca->id,
        ]);
        $paid = $svc->pay($debt->installments->first(), $this->owner, ['paid_date' => now()->toDateString(), 'cash_account_id' => $this->bca->id]);

        foreach ([$paid->journal_entry_id, $debt->disbursement_journal_id] as $id) {
            try {
                app(JournalService::class)->void(JournalEntry::find($id), 'coba');
                $this->fail('Harus ditolak');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('menu Hutang', collect($e->errors())->flatten()->implode(' '));
            }
        }

        // Lewat menu Hutang tetap bisa.
        $svc->cancelPayment($paid, $this->owner);
        $this->assertFalse($paid->fresh()->isPaid());
        $this->assertTrue(JournalEntry::isSystemDocumentNumber('HT2611-001/1'));
    }

    // ── 10. Pekerjaan tambah ─────────────────────────────────────────────

    public function test_pekerjaan_tambah_tanggal_invoice_hari_persetujuan_dan_ditolak_bila_proyek_batal(): void
    {
        $svc = app(ProjectAddendumService::class);
        $p = $this->project();
        $add = $svc->submit($p, $this->admin, ['tanggal' => now()->subDays(3)->toDateString(), 'uraian' => 'Tambah gorong-gorong', 'nilai' => 5_000_000]);

        $approved = $svc->approve($add, $this->owner);
        $this->assertSame(today()->toDateString(), Invoice::find($approved->invoice_id)->invoice_date->toDateString());

        $other = $svc->submit($p->fresh(), $this->admin, ['uraian' => 'Tambah drainase', 'nilai' => 1_000_000]);
        $p->update(['status' => 'batal']);
        $this->expectException(ValidationException::class);
        $svc->approve($other, $this->owner);
    }

    // ── 11. Termin lunas dari DP bisa dibatalkan ────────────────────────

    public function test_termin_lunas_dari_dp_bisa_dibatalkan_dan_counter_kembali(): void
    {
        $svc = app(ProjectService::class);
        $p = $this->project();
        $svc->terimaDP($p, $this->bca, 100_000_000);
        $svc->tagihTermin($p->fresh(), 30);                        // 30 jt, lunas penuh dari DP
        $termin = ProjectTermin::sole();
        $this->assertNull($termin->invoice_id);
        $this->assertEquals(30, (float) $p->fresh()->tertagih_pct);

        $svc->cancelDpOnlyTermin($termin, 'salah persen');

        $p->refresh();
        $this->assertEquals(0, (float) $p->tertagih_pct);
        $this->assertEquals(0, (float) $p->dp_dipotong);
        $this->assertSame(0, ProjectTermin::count());
        $this->assertSame('void', JournalEntry::find($termin->dp_journal_id)->status);
    }

    public function test_void_manual_jurnal_dppot_termin_tanpa_invoice_ikut_membatalkan_termin(): void
    {
        $svc = app(ProjectService::class);
        $p = $this->project();
        $svc->terimaDP($p, $this->bca, 100_000_000);
        $svc->tagihTermin($p->fresh(), 20);
        $termin = ProjectTermin::sole();

        app(JournalService::class)->void(JournalEntry::find($termin->dp_journal_id), 'dari jurnal umum');

        $this->assertSame(0, ProjectTermin::count());
        $this->assertEquals(0, (float) $p->fresh()->tertagih_pct);
        $this->assertEquals(0, (float) $p->fresh()->dp_dipotong);
    }

    // ── 12. Stok dicek ulang setelah lock ───────────────────────────────

    public function test_penjualan_ditolak_bila_stok_habis_setelah_lock(): void
    {
        $material = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        app(MaterialPurchaseService::class)->record([
            'material_id' => $material->id, 'purchase_date' => now()->toDateString(),
            'qty' => 10, 'unit_price' => 80_000, 'payment_method' => 'tunai', 'cash_account_id' => $this->bca->id,
        ]);
        // Simulasi penjualan lain yang lebih dulu commit: stok di DB sudah turun,
        // tapi objek yang dipegang form (dibaca sebelumnya) masih 10.
        \Illuminate\Support\Facades\DB::table('materials')->where('id', $material->id)->update(['current_stock' => 2]);
        $this->partialMockMaterialRead($material);

        $this->expectException(ValidationException::class);
        app(MaterialSaleService::class)->create([
            'material_id' => $material->id, 'client_id' => $this->createClient($this->company)->id,
            'sale_date' => now()->toDateString(), 'metode' => 'tunai', 'cash_account_id' => $this->bca->id,
            'volume' => 8, 'harga_satuan' => 150_000,
        ]);
    }

    /** Material::findOrFail di awal create() dibaca dari cache objek dengan stok lama 10. */
    private function partialMockMaterialRead(Material $material): void
    {
        Material::retrieved(function (Material $m) use ($material) {
            if ($m->id === $material->id && ! app()->bound('wave5.first-read')) {
                app()->instance('wave5.first-read', true);
                $m->setRawAttributes(array_merge($m->getAttributes(), ['current_stock' => 10]), true);
            }
        });
    }

    // ── 13. Aset impor tidak disusutkan dobel ────────────────────────────

    public function test_bulan_yang_tercakup_akumulasi_awal_tidak_disusutkan_lagi(): void
    {
        $asset = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-9', 'name' => 'Dump lama', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-15', 'purchase_price' => 96_000_000, 'salvage_value' => 0, 'useful_life_months' => 96, 'status' => 'aktif',
        ]);
        // Bulan beli tidak disusutkan: Feb 2024 .. bulan lalu.
        $asOf = now()->startOfMonth();
        $months = (int) Carbon::parse('2024-02-01')->diffInMonths($asOf);
        $this->assertSame($months * 1_000_000, $asset->estimatedAkumulasiAwal($asOf));

        $asset->update(['akumulasi_awal' => $asset->estimatedAkumulasiAwal()]);
        $this->assertSame(now()->subMonthNoOverflow()->endOfMonth()->toDateString(), $asset->fresh()->akumulasi_awal_per->toDateString());

        $last = now()->subMonthNoOverflow();
        app(DepreciationService::class)->runForCompany($this->company, $last->year, $last->month);
        $this->assertSame(0, JournalEntry::where('document_number', sprintf('DEP-%d-%04d%02d', $asset->id, $last->year, $last->month))->count(),
            'bulan lalu sudah tercakup akumulasi awal');
    }
}
