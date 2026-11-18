<?php

namespace Tests\Feature\Audit;

use App\Enums\QuickTransactionType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Debt;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialSale;
use App\Models\Project;
use App\Models\ProjectUnitLog;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\DebtService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\MaterialMarginService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use App\Services\Accounting\ProjectService;
use App\Services\Accounting\ProjectUnitLogService;
use App\Services\Accounting\QuickTransactionService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Audit ulang 2026-11-18 — gelombang 1 (prioritas tinggi). */
class AuditWave4Test extends TestCase
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

    private function sum(string $code): float
    {
        return (float) DB::table('journal_entry_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $this->company->id)->where('je.status', 'posted')->where('je.document_type', '!=', 'pembalik')
            ->where('a.code', $code)->sum(DB::raw('jl.debit - jl.kredit'));
    }

    private function closeLastMonth(): Carbon
    {
        $last = now()->subMonthNoOverflow()->startOfMonth();
        AccountingPeriod::create(['company_id' => $this->company->id, 'period_year' => $last->year, 'period_month' => $last->month, 'status' => 'closed']);

        return $last;
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

    // ── 1. Potongan DP dibatasi nilai termin ─────────────────────────────

    public function test_potongan_dp_tidak_melebihi_nilai_termin_pendapatan_tidak_melebihi_kontrak(): void
    {
        $svc = app(ProjectService::class);
        $p = $this->project();
        $svc->tagihTermin($p, 10);                                          // 10 jt, belum ada DP
        $svc->terimaDP($p->fresh(), $this->bca, 90_000_000);
        $svc->tagihTermin($p->fresh(), 80);                                 // potong 72 jt
        $svc->tagihTermin($p->fresh(), 10);                                 // penutup: maks. 10 jt, bukan sisa 18 jt

        $p->refresh();
        $this->assertEquals(82_000_000, $p->dp_dipotong);
        $this->assertEquals(-100_000_000, $this->sum('441400'), 'pendapatan = nilai kontrak');
        $this->assertEquals(-8_000_000, $this->sum('221170'), 'kelebihan DP tetap kewajiban');
    }

    public function test_termin_lunas_dari_dp_ditolak_di_periode_tertutup(): void
    {
        $svc = app(ProjectService::class);
        $p = $this->project();
        $svc->terimaDP($p, $this->bca, 100_000_000);
        $last = $this->closeLastMonth();

        $this->expectException(ValidationException::class);
        $svc->tagihTermin($p->fresh(), 30, $last->copy()->addDays(5));
    }

    // ── 6b. Edit pemakaian unit di periode tertutup ──────────────────────

    public function test_edit_log_pemakaian_unit_dari_periode_tertutup_ditolak(): void
    {
        $asset = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'EX-1', 'name' => 'PC200', 'type' => 'excavator',
            'purchase_date' => '2024-01-01', 'purchase_price' => 100_000_000, 'salvage_value' => 0, 'useful_life_months' => 96, 'status' => 'aktif',
        ]);
        $svc = app(ProjectUnitLogService::class);
        $last = now()->subMonthNoOverflow()->startOfMonth();
        $log = $svc->create($this->project(), ['log_date' => $last->toDateString(), 'asset_id' => $asset->id, 'jam_kerja' => 8, 'gaji' => 100_000]);
        $this->closeLastMonth();

        try {
            $svc->update($log, ['log_date' => now()->toDateString(), 'asset_id' => $asset->id, 'jam_kerja' => 8, 'gaji' => 200_000]);
            $this->fail('Edit log dari periode tertutup harus ditolak');
        } catch (ValidationException) {
        }
        $this->assertTrue(JournalEntry::find($log->fresh()->journal_entry_id)->isPosted(), 'jurnal periode tertutup tidak di-void');
    }

    // ── 2 & 3. Pembelian kredit, hutang vendor, Bayar Utang ─────────────

    private function creditPurchase(): \App\Models\MaterialPurchase
    {
        $vendor = Vendor::create(['company_id' => $this->company->id, 'code' => 'V1', 'name' => 'CV Batu', 'is_active' => true]);

        return app(MaterialPurchaseService::class)->record([
            'material_id' => Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail()->id,
            'vendor_id' => $vendor->id, 'purchase_date' => now()->toDateString(),
            'qty' => 10, 'unit_price' => 100_000, 'payment_method' => 'kredit', 'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_void_pembelian_kredit_menghapus_hutang_vendornya(): void
    {
        $purchase = $this->creditPurchase();
        $this->assertSame(1, Debt::count());

        app(JournalService::class)->void(JournalEntry::find($purchase->journal_entry_id), 'salah input');

        $this->assertSame(0, Debt::count());
        $this->assertSame(0, \App\Models\DebtInstallment::count());
    }

    public function test_void_pembelian_kredit_ditolak_bila_hutangnya_sudah_dibayar(): void
    {
        $purchase = $this->creditPurchase();
        app(DebtService::class)->pay(Debt::sole()->installments->first(), $this->owner,
            ['paid_date' => now()->toDateString(), 'cash_account_id' => $this->bca->id]);

        try {
            app(JournalService::class)->void(JournalEntry::find($purchase->journal_entry_id), 'salah input');
            $this->fail('Void harus ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sudah ada pembayaran', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertTrue(JournalEntry::find($purchase->journal_entry_id)->isPosted());
    }

    public function test_bayar_utang_di_transaksi_ditolak_bila_ada_hutang_vendor_berjalan(): void
    {
        $svc = app(QuickTransactionService::class);
        $this->creditPurchase();

        try {
            $svc->post($this->company, QuickTransactionType::BayarUtang, $this->bca, 1_000_000, now(), method: 'bank');
            $this->fail('Harus diarahkan ke menu Hutang');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Hutang & Cicilan', collect($e->errors())->flatten()->implode(' '));
        }

        // Setelah hutangnya lunas lewat menu Hutang, penjaga tidak lagi menolak.
        // (Posting quick_tx sendiri tidak bisa diuji di SQLite: enum document_type
        // 'quick_tx' hanya ditambahkan migrasi MySQL — cukup pastikan penjaganya lolos.)
        app(DebtService::class)->pay(Debt::sole()->installments->first(), $this->owner,
            ['paid_date' => now()->toDateString(), 'cash_account_id' => $this->bca->id]);
        try {
            $svc->post($this->company, QuickTransactionType::BayarUtang, $this->bca, 100_000, now(), method: 'bank');
        } catch (ValidationException $e) {
            $this->fail('Tidak boleh ditolak lagi: ' . collect($e->errors())->flatten()->implode(' '));
        } catch (\Illuminate\Database\QueryException) {
            // enum quick_tx di SQLite — di luar cakupan test ini
        }
    }

    // ── 4. Batal penjualan material tunai ────────────────────────────────

    private function tunaiSale(): MaterialSale
    {
        $material = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        app(MaterialPurchaseService::class)->record([
            'material_id' => $material->id, 'purchase_date' => now()->toDateString(),
            'qty' => 50, 'unit_price' => 80_000, 'payment_method' => 'tunai', 'cash_account_id' => $this->bca->id,
        ]);

        return app(MaterialSaleService::class)->create([
            'material_id' => $material->id, 'client_id' => $this->createClient($this->company)->id,
            'sale_date' => now()->toDateString(), 'metode' => 'tunai', 'cash_account_id' => $this->bca->id,
            'volume' => 10, 'harga_satuan' => 150_000, 'uang_makan_supir' => 100_000, 'biaya_cash_account_id' => $this->bca->id,
        ]);
    }

    public function test_batal_penjualan_tunai_mengembalikan_stok_dan_membalik_semua_jurnal(): void
    {
        $sale = $this->tunaiSale();
        $material = Material::withoutGlobalScopes()->find($sale->material_id);
        $this->assertEquals(40, (float) $material->current_stock);
        $this->assertEquals(-1_500_000, $this->sum('441300'));

        app(MaterialSaleService::class)->voidTunai($sale, $this->owner, 'Klien batal');

        $this->assertTrue($sale->fresh()->isVoided());
        $this->assertEquals(50, (float) $material->fresh()->current_stock);
        $this->assertEquals(0, $this->sum('441300'), 'pendapatan dibalik');
        $this->assertEquals(0, $this->sum('551300'), 'HPP dibalik');
        $this->assertEquals(0, $this->sum('551200'), 'biaya kirim dibalik');
        $this->assertSame(0, app(MaterialMarginService::class)->report($this->company->id, now()->startOfMonth(), now())['rows']->count());

        $this->expectException(ValidationException::class);
        app(MaterialSaleService::class)->voidTunai($sale->fresh(), $this->owner, 'dua kali');
    }

    public function test_void_manual_jurnal_penjualan_tunai_ikut_membatalkan_penjualan(): void
    {
        $sale = $this->tunaiSale();
        app(JournalService::class)->void(JournalEntry::find($sale->journal_entry_id), 'void dari jurnal umum');

        $this->assertTrue($sale->fresh()->isVoided());
        $this->assertEquals(50, (float) Material::withoutGlobalScopes()->find($sale->material_id)->current_stock);
        $this->assertEquals(0, $this->sum('551300'));
    }

    public function test_void_manual_jurnal_hpp_saja_ditolak(): void
    {
        $sale = $this->tunaiSale();
        $hpp = JournalEntry::where('source_type', $sale->getMorphClass())->where('source_id', $sale->id)
            ->where('document_number', 'like', 'HPP-%')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(JournalService::class)->void($hpp, 'coba');
    }

    // ── 5. Aturan pindah dana & saldo awal ───────────────────────────────

    public function test_transfer_plus_biaya_admin_dan_saldo_awal_wajib_owner(): void
    {
        $this->actingAsTenant($this->admin, $this->company);
        $admin = $this->postableAccount($this->company, '552500');
        $transferFee = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->mandiri->id, 'debit' => 1_000_000, 'kredit' => 0],
            ['account_id' => $admin->id, 'debit' => 6_500, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 1_006_500],
        ]);
        $saldoAwal = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->mandiri->id, 'debit' => 5_000_000, 'kredit' => 0],
            ['account_id' => $this->postableAccount($this->company, '331100')->id, 'debit' => 0, 'kredit' => 5_000_000],
        ], ['document_type' => 'saldo_awal']);
        $bebanBank = $this->makeJournalEntry($this->company, [
            ['account_id' => $admin->id, 'debit' => 6_500, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 6_500],
        ]);

        foreach (['transfer + biaya' => $transferFee, 'saldo awal' => $saldoAwal] as $name => $entry) {
            try {
                app(JournalService::class)->post($entry);
                $this->fail("{$name} oleh admin harus ditolak");
            } catch (ValidationException) {
            }
        }
        app(JournalService::class)->post($bebanBank);   // beban biasa dari bank: boleh
        $this->assertSame('posted', $bebanBank->fresh()->status);
        $this->assertEqualsCanonicalizing([$transferFee->id, $saldoAwal->id],
            JournalEntry::pendingOwnerApproval($this->company->id)->pluck('id')->all());

        $this->actingAsTenant($this->owner, $this->company);
        app(JournalService::class)->post($transferFee->fresh());
        app(JournalService::class)->post($saldoAwal->fresh());
        $this->assertSame(0, JournalEntry::pendingOwnerApproval($this->company->id)->count());
    }
}
