<?php

namespace Tests\Feature\Audit;

use App\Enums\MaintenanceType;
use App\Enums\MaterialJenis;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Material;
use App\Models\MaterialSale;
use App\Models\User;
use App\Services\Accounting\AssetDepreciationReportService;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\MaintenanceService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regresi audit Gelombang 3 (17 Nov 2026) — stok, sparepart & aset. */
class AuditWave3Test extends TestCase
{
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
    }

    private function saldo(string $code): float
    {
        return (float) (app(TrialBalanceService::class)->getBalances($this->company->id, (int) now()->year)
            ->firstWhere('code', $code)->saldo ?? 0);
    }

    private function buy(Material $m, float $qty, int $price): void
    {
        app(MaterialPurchaseService::class)->record([
            'material_id' => $m->id, 'purchase_date' => now()->toDateString(),
            'qty' => $qty, 'unit_price' => $price, 'payment_method' => 'tunai',
        ]);
    }

    // ── Void invoice penjualan material ─────────────────────────────────

    public function test_void_invoice_penjualan_membalik_hpp_dan_persediaan_cocok_dengan_stok(): void
    {
        $m = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->buy($m, 20, 100_000);

        $sale = app(MaterialSaleService::class)->create([
            'material_id' => $m->id, 'client_id' => $this->createClient($this->company)->id,
            'sale_date' => now()->toDateString(), 'volume' => 8, 'harga_satuan' => 150_000, 'metode' => 'invoice',
        ]);
        app(InvoiceService::class)->void(\App\Models\Invoice::find($sale->invoice_id), 'batal');

        $m->refresh();
        $this->assertEquals(20, (float) $m->current_stock);
        $this->assertEquals(0, $this->saldo('551300'), 'HPP tanpa pendapatan tidak boleh tersisa');
        $this->assertEquals((float) $m->current_stock * (float) $m->current_mac, $this->saldo('111260'), 'persediaan buku = nilai stok');
    }

    // ── Penjualan tanpa akun HPP ────────────────────────────────────────

    public function test_penjualan_tanpa_akun_hpp_ditolak_dan_stok_utuh(): void
    {
        $m = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->buy($m, 10, 100_000);
        Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '551300')
            ->update(['role' => null, 'code' => '9551300']);

        try {
            app(MaterialSaleService::class)->create([
                'material_id' => $m->id, 'client_id' => $this->createClient($this->company)->id,
                'sale_date' => now()->toDateString(), 'volume' => 3, 'harga_satuan' => 150_000, 'metode' => 'tunai',
            ]);
            $this->fail('Seharusnya ditolak');
        } catch (ValidationException) {
            $this->assertEquals(10, (float) $m->fresh()->current_stock);
            $this->assertSame(0, MaterialSale::count());
        }
    }

    // ── Edit servis bersparepart ────────────────────────────────────────

    public function test_edit_servis_tanpa_ubah_sparepart_tidak_menggeser_nilai(): void
    {
        $sp = Material::create([
            'company_id' => $this->company->id, 'code' => 'SP-' . Str::random(4), 'name' => 'Filter',
            'jenis' => MaterialJenis::Sparepart->value, 'harga_per_satuan' => 0, 'harga_pokok' => 0, 'satuan' => 'pcs', 'is_active' => true,
        ]);
        $truck = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-01', 'name' => 'DT', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0, 'useful_life_months' => 96, 'status' => 'aktif',
        ]);
        $this->buy($sp, 10, 100_000);
        $svc = app(MaintenanceService::class);
        $data = [
            'asset_id' => $truck->id, 'maintenance_date' => now()->toDateString(), 'type' => MaintenanceType::ServiceRutin->value,
            'description' => 'Ganti filter', 'cost' => 0, 'items' => [['material_id' => $sp->id, 'qty' => 5]],
        ];
        $log = $svc->log($data);
        $this->buy($sp, 5, 200_000);    // MAC naik ke 150rb

        $svc->updateLog($log->fresh(), array_merge($data, ['description' => 'Ganti filter oli']));

        $sp->refresh();
        $this->assertEquals(500_000, $this->saldo('551400'), 'beban tetap 5 × 100rb');
        $this->assertEqualsWithDelta((float) $sp->current_stock * (float) $sp->current_mac, $this->saldo('111270'), 1, 'persediaan buku = nilai stok');
    }

    // ── Aset ────────────────────────────────────────────────────────────

    private function asset(array $attrs = []): Asset
    {
        return Asset::create(array_merge([
            'company_id' => $this->company->id, 'asset_code' => 'DT-' . random_int(10, 99), 'name' => 'DT', 'type' => 'dump_truck',
            'purchase_date' => now()->subMonths(3)->startOfMonth()->toDateString(), 'purchase_price' => 96_000_000,
            'salvage_value' => 0, 'useful_life_months' => 96, 'status' => 'aktif',
        ], $attrs));
    }

    public function test_data_penentu_penyusutan_terkunci_setelah_disusutkan(): void
    {
        $a = $this->asset();
        app(DepreciationService::class)->runForCompany($this->company, (int) now()->year, (int) now()->month);
        $this->assertTrue($a->fresh()->hasPostedDepreciationJournal());

        foreach (['type' => 'kendaraan_operasional', 'purchase_price' => 50_000_000, 'useful_life_months' => 12] as $field => $value) {
            try {
                $a->fresh()->update([$field => $value]);
                $this->fail("{$field} seharusnya terkunci");
            } catch (ValidationException) {
            }
        }
        $a->fresh()->update(['name' => 'Dump Truck 01']);   // field lain tetap bebas
        $this->assertSame('Dump Truck 01', $a->fresh()->name);
    }

    public function test_aset_lama_yang_umurnya_habis_bernilai_buku_nol_dan_tidak_disusutkan(): void
    {
        $a = $this->asset(['purchase_date' => '2015-07-01']);
        $a->update(['akumulasi_awal' => $a->estimatedAkumulasiAwal()]);
        $this->assertEquals(96_000_000, $a->akumulasi_awal);

        app(DepreciationService::class)->runForCompany($this->company, (int) now()->year, (int) now()->month);

        $this->assertFalse($a->fresh()->hasPostedDepreciationJournal());
        $row = collect(app(AssetDepreciationReportService::class)->getReport($this->company->id, (int) now()->year, (int) now()->month)['rows'])
            ->firstWhere('asset_id', $a->id) ?? collect(app(AssetDepreciationReportService::class)->getReport($this->company->id, (int) now()->year, (int) now()->month)['rows'])->firstWhere('asset_code', $a->asset_code);
        $this->assertEquals(0, $row['nilai_buku']);
    }

    public function test_aset_setengah_umur_hanya_menyusutkan_sisa_nilai(): void
    {
        // Dibeli 92 bulan lalu → akumulasi awal 92 × 1jt; sisa 4 bulan × 1jt.
        $a = $this->asset(['purchase_date' => now()->startOfMonth()->subMonths(92)->toDateString()]);
        $a->update(['akumulasi_awal' => $a->estimatedAkumulasiAwal()]);
        $this->assertEquals(92_000_000, $a->akumulasi_awal);

        $svc = app(DepreciationService::class);
        $month = now()->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $d = $month->copy()->addMonths($i);
            $svc->runForCompany($this->company, $d->year, $d->month);
        }

        $posted = \App\Models\JournalEntry::withoutGlobalScopes()->where('document_number', 'like', "DEP-{$a->id}-%")->sum('total_amount');
        $this->assertEquals(4_000_000, $posted, 'total penyusutan tidak melebihi sisa nilai');
    }
}
