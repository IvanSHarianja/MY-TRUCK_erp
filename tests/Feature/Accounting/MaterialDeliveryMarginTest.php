<?php

namespace Tests\Feature\Accounting;

use App\Filament\Resources\MaterialSales\Pages\MaterialMarginReport;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\RitLog;
use App\Models\User;
use App\Services\Accounting\ArmadaContractService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\MaterialMarginService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Masukan atasan 2026-11-18: ongkos gendong per ton; penjualan material per
 * rit + biaya pengiriman armada per penjualan + laporan margin.
 */
class MaterialDeliveryMarginTest extends TestCase
{
    private Company $company;
    private User $owner;
    private Material $pasir;
    private Asset $truk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');

        $this->pasir = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        app(MaterialPurchaseService::class)->record([
            'material_id' => $this->pasir->id, 'purchase_date' => now()->toDateString(),
            'qty' => 100, 'unit_price' => 80_000, 'payment_method' => 'tunai',
        ]);
        $this->truk = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-01', 'name' => 'DT', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0, 'useful_life_months' => 96, 'status' => 'aktif',
        ]);
    }

    private function sale(array $overrides = []): \App\Models\MaterialSale
    {
        return app(MaterialSaleService::class)->create(array_merge([
            'material_id' => $this->pasir->id, 'client_id' => $this->createClient($this->company)->id,
            'sale_date' => now()->toDateString(), 'metode' => 'invoice',
            'satuan_jual' => 'rit', 'jumlah_rit' => 10, 'volume' => 60, 'harga_satuan' => 900_000,
            'asset_id' => $this->truk->id,
            'ongkos_supir_per_rit' => 50_000, 'bm_per_rit' => 30_000, 'uang_makan_supir' => 100_000,
            'biaya_bbm' => 400_000, 'biaya_portal' => 50_000, 'biaya_lain' => 20_000, 'biaya_lain_ket' => 'parkir',
        ], $overrides));
    }

    private function lineSum(string $code, ?int $assetId = null): float
    {
        return (float) \Illuminate\Support\Facades\DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $this->company->id)->where('je.status', 'posted')->where('je.document_type', '!=', 'pembalik')
            ->where('a.code', $code)
            ->when($assetId, fn ($q) => $q->where('jl.asset_id', $assetId))
            ->sum(\Illuminate\Support\Facades\DB::raw('jl.debit - jl.kredit'));
    }

    // ── A. Ongkos gendong per ton ───────────────────────────────────────

    public function test_kontrak_armada_per_ton_menagih_tonase(): void
    {
        $c = ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA26-0009', 'client_id' => $this->createClient($this->company)->id,
            'tipe_kontrak' => 'alat_saja', 'route_description' => 'Kuari → Batching', 'satuan_tarif' => 'per_ton',
            'tarif_per_rit' => 30_000, 'billed_rit' => 0, 'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->owner->id,
        ]);
        RitLog::create([
            'company_id' => $this->company->id, 'armada_contract_id' => $c->id, 'asset_id' => $this->truk->id,
            'log_date' => now()->toDateString(), 'rit_count' => 4, 'tonase' => 62.5, 'created_by' => $this->owner->id,
        ]);

        $this->assertSame('62,5 ton', $c->fresh()->unbilledLabel());
        $inv = app(ArmadaContractService::class)->tagih($c->fresh());
        $this->assertEquals(62.5 * 30_000, $inv->amount);
        $this->assertStringContainsString('62,5 ton @ Rp 30.000', $inv->description);
    }

    // ── B. Penjualan per rit + biaya kirim ──────────────────────────────

    public function test_penjualan_per_rit_dengan_biaya_kirim_dan_margin(): void
    {
        $s = $this->sale();

        $this->assertEquals(9_000_000, $s->total, '10 rit × 900rb');
        $this->assertEquals(40, (float) $this->pasir->fresh()->current_stock, 'stok berkurang 60 m³');
        $this->assertEquals(60 * 80_000, $s->hpp_total);
        $this->assertEquals(10 * 80_000 + 100_000 + 400_000 + 50_000 + 20_000, $s->biaya_kirim_total);
        $this->assertEquals(9_000_000 - 4_800_000 - 1_370_000, $s->margin());
        $this->assertStringContainsString('10 rit (60', Invoice::find($s->invoice_id)->description);

        // Jurnal biaya kirim per Peran akun, ditandai truk.
        $this->assertEquals(10 * 50_000 + 100_000 + 50_000, $this->lineSum('551200', $this->truk->id), 'supir + UM + portal');
        $this->assertEquals(400_000, $this->lineSum('551100', $this->truk->id), 'BBM');
        $this->assertEquals(10 * 30_000, $this->lineSum('551600', $this->truk->id), 'BM per rit');
        $this->assertTrue(JournalEntry::find($s->biaya_journal_id)->isBalanced());

        $r = app(MaterialMarginService::class)->report($this->company->id, Carbon::today(), Carbon::today());
        $this->assertEquals(2_830_000, $r['totals']['margin']);
    }

    public function test_void_invoice_membatalkan_biaya_kirim_dan_hilang_dari_laporan_margin(): void
    {
        $s = $this->sale();
        app(InvoiceService::class)->void(Invoice::find($s->invoice_id), 'batal');

        $this->assertSame('void', JournalEntry::find($s->biaya_journal_id)->status);
        $this->assertEquals(0, $this->lineSum('551600'));
        $this->assertCount(0, app(MaterialMarginService::class)->report($this->company->id, Carbon::today(), Carbon::today())['rows']);
    }

    public function test_penjualan_per_satuan_tanpa_biaya_kirim_tetap_seperti_dulu(): void
    {
        $s = $this->sale(['satuan_jual' => 'satuan', 'jumlah_rit' => null, 'volume' => 5, 'harga_satuan' => 150_000,
            'ongkos_supir_per_rit' => 0, 'bm_per_rit' => 0, 'uang_makan_supir' => 0, 'biaya_bbm' => 0, 'biaya_portal' => 0, 'biaya_lain' => 0]);

        $this->assertEquals(750_000, $s->total);
        $this->assertNull($s->biaya_journal_id);
        $this->assertEquals(750_000 - 400_000, $s->margin());
    }

    public function test_validasi_rit_dan_truk_pt_lain(): void
    {
        foreach ([
            ['jumlah_rit' => null],                                   // jual per rit tanpa jumlah rit
            ['satuan_jual' => 'satuan', 'jumlah_rit' => null],        // ongkos per rit tanpa rit
            ['asset_id' => Asset::create([                            // truk milik PT lain
                'company_id' => $this->createTenant()->id, 'asset_code' => 'X-1', 'name' => 'X', 'type' => 'dump_truck',
                'purchase_date' => '2024-01-01', 'purchase_price' => 1, 'salvage_value' => 0, 'useful_life_months' => 12, 'status' => 'aktif',
            ])->id],
        ] as $i => $bad) {
            try {
                $this->sale($bad);
                $this->fail("kasus {$i} seharusnya ditolak");
            } catch (ValidationException) {
            }
        }
        $this->assertEquals(100, (float) $this->pasir->fresh()->current_stock, 'tidak ada stok yang berkurang');
    }

    public function test_form_buat_penjualan_menyimpan_biaya_kirim(): void
    {
        Livewire::test(\App\Filament\Resources\MaterialSales\Pages\CreateMaterialSale::class)
            ->fillForm([
                'sale_date' => now()->toDateString(), 'client_id' => $this->createClient($this->company)->id, 'metode' => 'invoice',
                'material_id' => $this->pasir->id, 'satuan_jual' => 'rit', 'jumlah_rit' => 5, 'volume' => 30,
                'harga_satuan' => '900000', 'asset_id' => $this->truk->id,
                'ongkos_supir_per_rit' => '50000', 'biaya_bbm' => '200000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $s = \App\Models\MaterialSale::latest('id')->firstOrFail();
        $this->assertEquals(4_500_000, $s->total);
        $this->assertEquals(5 * 50_000 + 200_000, $s->biaya_kirim_total);
        $this->assertSame($this->truk->id, $s->asset_id);
    }

    public function test_halaman_dan_export_laporan_margin(): void
    {
        $this->sale();

        Livewire::test(MaterialMarginReport::class)->assertOk()->assertSee('2.830.000');
        $this->get(route('export.margin-material', [
            'tenant' => $this->company->slug, 'dari' => now()->toDateString(), 'sampai' => now()->toDateString(),
        ]))->assertOk()->assertDownload();
    }
}
