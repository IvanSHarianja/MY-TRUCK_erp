<?php

namespace Tests\Feature\Accounting;

use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\MaterialSale;
use App\Models\MaterialStockMovement;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use Tests\TestCase;

/**
 * BIZ-01 hardening — cascade void behavior untuk purchase & sale.
 *
 * Cakupan:
 *  - Void jurnal PB → rollback stock + recompute MAC
 *  - Delete MaterialPurchase (setelah punya movement) → throw
 *  - Void invoice hasil sale → restore stock (kalau ada movement)
 *  - Void sale legacy (tanpa movement) → skip restore, tidak crash
 */
class MaterialCascadeVoidTest extends TestCase
{
    private MaterialPurchaseService $purchaseService;
    private MaterialSaleService $saleService;
    private JournalService $journalService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchaseService = app(MaterialPurchaseService::class);
        $this->saleService     = app(MaterialSaleService::class);
        $this->journalService  = app(JournalService::class);
    }

    private function firstMaterial(\App\Models\Company $company): Material
    {
        return Material::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();
    }

    // ============================================================
    // Void Purchase → rollback stock + MAC
    // ============================================================

    public function test_void_purchase_rollback_stock_dan_recompute_mac(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        // 2 purchase → stock 15, MAC = (10×200rb + 5×260rb)/15 = 220rb
        $purchase1 = $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 10, 'unit_price' => 200_000, 'payment_method' => 'tunai',
        ]);
        $purchase2 = $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-02',
            'qty' => 5, 'unit_price' => 260_000, 'payment_method' => 'tunai',
        ]);

        $material->refresh();
        $this->assertSame(15.0, (float) $material->current_stock);
        $this->assertSame(220_000.0, (float) $material->current_mac);

        // Void purchase 2
        $journal2 = JournalEntry::withoutGlobalScopes()->find($purchase2->journal_entry_id);
        $this->journalService->void($journal2, 'Salah input');

        // Stock kurangi 5 → 10, MAC recompute dari purchase 1 = 200rb
        $material->refresh();
        $this->assertSame(10.0, (float) $material->current_stock,
            'Void purchase harus rollback stock dari 15 ke 10'
        );
        $this->assertSame(200_000.0, (float) $material->current_mac,
            'MAC harus recompute dari purchase yang masih aktif = 200rb'
        );

        // Audit adjustment movement tercatat
        $adjustment = MaterialStockMovement::withoutGlobalScopes()
            ->where('material_id', $material->id)
            ->where('movement_type', 'adjustment')
            ->latest('id')
            ->first();
        $this->assertNotNull($adjustment);
        $this->assertSame(-5.0, (float) $adjustment->qty_change);
    }

    public function test_void_last_purchase_reset_mac_ke_zero(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        // Purchase tunggal
        $purchase = $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 5, 'unit_price' => 150_000, 'payment_method' => 'tunai',
        ]);

        $material->refresh();
        $this->assertSame(150_000.0, (float) $material->current_mac);

        // Void → sisa tidak ada purchase aktif → MAC = 0
        $journal = JournalEntry::withoutGlobalScopes()->find($purchase->journal_entry_id);
        $this->journalService->void($journal, 'reset');

        $material->refresh();
        $this->assertSame(0.0, (float) $material->current_stock);
        $this->assertSame(0.0, (float) $material->current_mac);
    }

    // ============================================================
    // Delete MaterialPurchase (guarded)
    // ============================================================

    public function test_delete_purchase_yang_punya_movement_ditolak(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        $purchase = $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 5, 'unit_price' => 150_000, 'payment_method' => 'tunai',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak bisa langsung dihapus/');
        $purchase->delete();
    }

    // ============================================================
    // Void invoice hasil sale → restore stock
    // ============================================================

    public function test_void_invoice_hasil_sale_restore_stock(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        // Purchase 20 unit
        $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 20, 'unit_price' => 100_000, 'payment_method' => 'tunai',
        ]);

        // Sale 8 unit via invoice (bukan tunai)
        $client = $this->createClient($company);
        $sale = $this->saleService->create([
            'material_id' => $material->id,
            'client_id'   => $client->id,
            'sale_date'   => '2026-08-05',
            'volume'      => 8,
            'metode'      => 'invoice',
        ]);

        $material->refresh();
        $this->assertSame(12.0, (float) $material->current_stock,
            'Setelah sale 8: stock 20 - 8 = 12'
        );

        // Void invoice — cascade restore stock via InvoiceObserver
        $invoice = \App\Models\Invoice::withoutGlobalScopes()->find($sale->invoice_id);
        $this->assertNotNull($invoice);

        app(\App\Services\Accounting\InvoiceService::class)->void($invoice, 'Test restore');

        $material->refresh();
        $this->assertSame(20.0, (float) $material->current_stock,
            'Void invoice harus restore stock kembali ke 20'
        );

        // Audit trail adjustment
        $restore = MaterialStockMovement::withoutGlobalScopes()
            ->where('material_id', $material->id)
            ->where('movement_type', 'adjustment')
            ->where('source_type', MaterialSale::class)
            ->latest('id')
            ->first();
        $this->assertNotNull($restore);
        $this->assertSame(8.0, (float) $restore->qty_change);
    }

    public function test_void_invoice_sale_legacy_tanpa_movement_tidak_crash(): void
    {
        // Sale legacy: material tanpa purchase, HPP pakai harga_pokok statis
        // → tidak ada stock movement OUT. Void invoice-nya tidak boleh crash.
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = Material::create([
            'company_id'       => $company->id,
            'code'             => 'LEG-01',
            'name'             => 'Legacy Material',
            'harga_per_satuan' => 300_000,
            'harga_pokok'      => 200_000,
            'satuan'           => 'm3',
            'is_active'        => true,
        ]);

        $client = $this->createClient($company);
        $sale = $this->saleService->create([
            'material_id' => $material->id,
            'client_id'   => $client->id,
            'sale_date'   => '2026-08-05',
            'volume'      => 5,
            'metode'      => 'invoice',
        ]);

        // Tidak ada stock movement (legacy)
        $this->assertSame(0, MaterialStockMovement::withoutGlobalScopes()
            ->where('source_type', MaterialSale::class)
            ->where('source_id', $sale->id)
            ->count());

        // Void invoice — harus sukses tanpa crash
        $invoice = \App\Models\Invoice::withoutGlobalScopes()->find($sale->invoice_id);
        app(\App\Services\Accounting\InvoiceService::class)->void($invoice, 'legacy void');

        // Stock tetap 0 (tidak berubah untuk legacy)
        $material->refresh();
        $this->assertSame(0.0, (float) $material->current_stock);
    }
}
