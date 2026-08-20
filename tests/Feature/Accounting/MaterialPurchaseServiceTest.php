<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\MaterialStockMovement;
use App\Models\Vendor;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BIZ-01 — Feature test Material Purchase + Stock Movement + MAC.
 *
 * Cakupan:
 *  - MaterialPurchase::record() → journal + stock + MAC update
 *  - MAC weighted-average calculation
 *  - Journal tunai: Dr Persediaan / Cr Kas
 *  - Journal kredit: Dr Persediaan / Cr Utang Vendor
 *  - Sale dengan MAC: HPP = MAC × qty, Cr Persediaan, stock berkurang
 *  - Sale legacy (no purchase): HPP dari harga_pokok statis, Cr Kas (backward-compat)
 *  - Negative stock guard aktif setelah material punya movement
 */
class MaterialPurchaseServiceTest extends TestCase
{
    private MaterialPurchaseService $purchaseService;
    private MaterialSaleService $saleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchaseService = app(MaterialPurchaseService::class);
        $this->saleService = app(MaterialSaleService::class);
    }

    private function createVendor(Company $company): Vendor
    {
        return Vendor::create([
            'company_id'    => $company->id,
            'code'          => 'VDR-' . strtoupper(Str::random(4)),
            'name'          => 'PT Vendor Test',
            'contact_person'=> 'Contact',
            'is_active'     => true,
        ]);
    }

    private function firstMaterial(Company $company): Material
    {
        // Ambil material pertama dari template default (7 material seeded per company)
        return Material::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();
    }

    // ============================================================
    // Purchase basic — journal + stock + MAC
    // ============================================================

    public function test_purchase_tunai_post_journal_dan_update_stock_mac(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);
        $vendor   = $this->createVendor($company);

        $purchase = $this->purchaseService->record([
            'material_id'    => $material->id,
            'vendor_id'      => $vendor->id,
            'purchase_date'  => '2026-08-01',
            'qty'            => 10,
            'unit_price'     => 200_000,
            'payment_method' => 'tunai',
        ]);

        $this->assertSame(2_000_000, (int) $purchase->total_amount);
        $this->assertNotNull($purchase->journal_entry_id);

        // Cek journal
        $journal = JournalEntry::withoutGlobalScopes()->find($purchase->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isPosted());
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(2_000_000.0, (float) $journal->total_amount);

        // Cek material stock + MAC updated
        $material->refresh();
        $this->assertSame(10.0, (float) $material->current_stock);
        $this->assertSame(200_000.0, (float) $material->current_mac);

        // Cek stock movement recorded
        $movement = MaterialStockMovement::withoutGlobalScopes()
            ->where('material_id', $material->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame('in', $movement->movement_type);
        $this->assertSame(10.0, (float) $movement->qty_change);
        $this->assertSame(200_000.0, (float) $movement->unit_cost);
        $this->assertSame(0.0, (float) $movement->stock_before);
        $this->assertSame(10.0, (float) $movement->stock_after);
    }

    // ============================================================
    // MAC weighted average
    // ============================================================

    public function test_mac_dihitung_weighted_average_dari_multi_purchase(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        // Purchase 1: 10 unit @ 200rb → MAC = 200rb
        $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 10, 'unit_price' => 200_000, 'payment_method' => 'tunai',
        ]);

        $material->refresh();
        $this->assertSame(10.0, (float) $material->current_stock);
        $this->assertSame(200_000.0, (float) $material->current_mac);

        // Purchase 2: 5 unit @ 260rb
        // MAC baru = (10×200rb + 5×260rb) / 15 = 220.000
        $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-02',
            'qty' => 5, 'unit_price' => 260_000, 'payment_method' => 'tunai',
        ]);

        $material->refresh();
        $this->assertSame(15.0, (float) $material->current_stock);
        $this->assertSame(220_000.0, (float) $material->current_mac);

        // Purchase 3: 5 unit @ 100rb — cek MAC turun
        // MAC baru = (15×220rb + 5×100rb) / 20 = 190.000
        $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-03',
            'qty' => 5, 'unit_price' => 100_000, 'payment_method' => 'tunai',
        ]);

        $material->refresh();
        $this->assertSame(20.0, (float) $material->current_stock);
        $this->assertSame(190_000.0, (float) $material->current_mac);
    }

    // ============================================================
    // Journal lines — tunai vs kredit
    // ============================================================

    public function test_purchase_kredit_post_ke_utang_vendor_bukan_kas(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);
        $vendor   = $this->createVendor($company);

        $purchase = $this->purchaseService->record([
            'material_id'    => $material->id,
            'vendor_id'      => $vendor->id,
            'purchase_date'  => '2026-08-01',
            'qty'            => 5,
            'unit_price'     => 100_000,
            'payment_method' => 'kredit',
        ]);

        $journal = JournalEntry::withoutGlobalScopes()
            ->with('lines.account')
            ->find($purchase->journal_entry_id);

        // Kredit line harus ke Utang Vendor (221100), bukan Kas
        $creditLine = $journal->lines->where('kredit', '>', 0)->first();
        $this->assertNotNull($creditLine);
        $this->assertSame('221100', $creditLine->account->code);
    }

    public function test_purchase_kredit_tanpa_vendor_ditolak(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->purchaseService->record([
            'material_id'    => $material->id,
            'vendor_id'      => null,
            'purchase_date'  => '2026-08-01',
            'qty'            => 5,
            'unit_price'     => 100_000,
            'payment_method' => 'kredit',
        ]);
    }

    // ============================================================
    // Sale dengan MAC (setelah purchase)
    // ============================================================

    public function test_sale_setelah_purchase_pakai_mac_dan_kurangi_stock(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        // Purchase 10 @ 200rb → MAC 200rb, stock 10
        $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 10, 'unit_price' => 200_000, 'payment_method' => 'tunai',
        ]);

        // Sale 3 unit @ jual 300rb
        $client = $this->createClient($company);
        $sale = $this->saleService->create([
            'material_id'  => $material->id,
            'client_id'    => $client->id,
            'sale_date'    => '2026-08-05',
            'volume'       => 3,
            'harga_satuan' => 300_000,
            'metode'       => 'tunai',
        ]);

        // Stock berkurang: 10 - 3 = 7
        $material->refresh();
        $this->assertSame(7.0, (float) $material->current_stock);
        // MAC tidak berubah saat sale
        $this->assertSame(200_000.0, (float) $material->current_mac);

        // Stock movement OUT dicatat
        $outMovement = MaterialStockMovement::withoutGlobalScopes()
            ->where('material_id', $material->id)
            ->where('movement_type', 'out')
            ->first();
        $this->assertNotNull($outMovement);
        $this->assertSame(-3.0, (float) $outMovement->qty_change);
        $this->assertSame(200_000.0, (float) $outMovement->unit_cost);
    }

    // ============================================================
    // Backward compat — sale legacy tanpa purchase
    // ============================================================

    public function test_sale_legacy_material_tanpa_purchase_tetap_sukses_pakai_harga_pokok_statis(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        // Material dengan harga_pokok set langsung (legacy — tanpa purchase)
        $material = Material::create([
            'company_id'       => $company->id,
            'code'             => 'LEG-01',
            'name'             => 'Legacy Material',
            'harga_per_satuan' => 300_000,
            'harga_pokok'      => 200_000,
            'satuan'           => 'm3',
            'is_active'        => true,
        ]);

        $this->assertSame(0.0, (float) $material->current_stock);
        $this->assertSame(0.0, (float) $material->current_mac);

        // Sale tetap boleh — material legacy, tidak ada movement history
        $client = $this->createClient($company);
        $sale = $this->saleService->create([
            'material_id' => $material->id,
            'client_id'   => $client->id,
            'sale_date'   => '2026-08-05',
            'volume'      => 5,
            'metode'      => 'tunai',
        ]);

        $this->assertNotNull($sale);
        $this->assertSame(5.0, (float) $sale->volume);
    }

    // ============================================================
    // Negative stock guard — aktif setelah ada purchase
    // ============================================================

    public function test_sale_ditolak_kalau_stok_kurang_setelah_ada_purchase(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAs($user);

        $material = $this->firstMaterial($company);

        // Purchase 5 unit
        $this->purchaseService->record([
            'material_id' => $material->id, 'purchase_date' => '2026-08-01',
            'qty' => 5, 'unit_price' => 200_000, 'payment_method' => 'tunai',
        ]);

        // Coba sale 10 unit (padahal stok cuma 5) → ditolak
        $client = $this->createClient($company);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessageMatches('/Stok .* tidak cukup/');
        $this->saleService->create([
            'material_id' => $material->id,
            'client_id'   => $client->id,
            'sale_date'   => '2026-08-05',
            'volume'      => 10,
            'metode'      => 'tunai',
        ]);
    }

    // ============================================================
    // Multi-tenant isolation
    // ============================================================

    public function test_purchase_terisolasi_per_company(): void
    {
        $companyA = $this->createTenant();
        $userA    = $this->createTenantUser($companyA);
        $companyB = $this->createTenant();

        $this->actingAs($userA);

        $materialA = $this->firstMaterial($companyA);
        $materialB = $this->firstMaterial($companyB);

        $this->purchaseService->record([
            'material_id' => $materialA->id, 'purchase_date' => '2026-08-01',
            'qty' => 10, 'unit_price' => 200_000, 'payment_method' => 'tunai',
        ]);

        // Material B tidak affected
        $materialB->refresh();
        $this->assertSame(0.0, (float) $materialB->current_stock);
        $this->assertSame(0.0, (float) $materialB->current_mac);
    }
}
