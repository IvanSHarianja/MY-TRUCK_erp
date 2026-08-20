<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\MaterialStockMovement;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * BIZ-01: Pembelian Material (Perpetual Inventory + Moving Average Cost).
 *
 * ALUR:
 * 1. record($data) → create MaterialPurchase
 * 2. Auto-post jurnal:
 *    - Tunai:  Dr Persediaan Material / Cr Kas
 *    - Kredit: Dr Persediaan Material / Cr Utang Vendor
 * 3. Auto-create MaterialStockMovement (movement_type='in')
 * 4. Auto-recalc Material.current_stock + current_mac (weighted avg)
 *
 * RACE SAFETY:
 * lockForUpdate pada Material row — 2 purchase bareng untuk material yang sama
 * di-serialize supaya perhitungan MAC tidak race.
 *
 * MAC FORMULA:
 *   MAC_baru = (stock_lama × MAC_lama + qty_beli × harga_beli) / (stock_lama + qty_beli)
 */
class MaterialPurchaseService
{
    public function __construct(private JournalService $journalService) {}

    /**
     * Auto-generate nomor purchase per company per bulan.
     * Format: PB{YY}{MM}-{NNNN}, contoh: PB2608-0001
     */
    public function generatePurchaseNumber(Company $company, CarbonInterface $date): string
    {
        $prefix = sprintf('PB%02d%02d-', $date->format('y'), $date->format('m'));

        $lastNumber = MaterialPurchase::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('purchase_number', 'like', $prefix . '%')
            ->orderByDesc('purchase_number')
            ->value('purchase_number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Record pembelian material — auto-journal + stock movement + MAC recalc.
     *
     * @param array{
     *     company_id: int,
     *     material_id: int,
     *     vendor_id?: int|null,
     *     purchase_date: string|CarbonInterface,
     *     qty: float,
     *     unit_price: int|float,
     *     payment_method?: 'tunai'|'kredit',
     *     cash_account_id?: int|null,
     *     notes?: string|null,
     *     bukti_tf_path?: string|null,
     *     created_by?: int,
     * } $data
     */
    public function record(array $data): MaterialPurchase
    {
        $material = Material::withoutGlobalScopes()->findOrFail($data['material_id']);
        $company  = Company::findOrFail($material->company_id);
        $vendor   = ! empty($data['vendor_id']) ? Vendor::withoutGlobalScopes()->find($data['vendor_id']) : null;
        $qty      = (float) $data['qty'];
        $unitPrice = (int) $data['unit_price'];
        $total    = (int) round($qty * $unitPrice);
        $purchaseDate = Carbon::parse($data['purchase_date'] ?? today());
        $method   = $data['payment_method'] ?? 'tunai';

        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Qty harus lebih dari 0.']);
        }
        if ($unitPrice <= 0) {
            throw ValidationException::withMessages(['unit_price' => 'Harga per unit harus lebih dari 0.']);
        }
        if (! in_array($method, ['tunai', 'kredit'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Metode bayar tidak valid.']);
        }
        if ($method === 'kredit' && ! $vendor) {
            throw ValidationException::withMessages(['vendor_id' => 'Vendor wajib diisi untuk pembelian kredit.']);
        }

        $this->journalService->assertPeriodOpen($company, $purchaseDate->year, $purchaseDate->month);

        $matlUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'MATL')
            ->first();

        return DB::transaction(function () use (
            $data, $company, $material, $vendor, $qty, $unitPrice, $total,
            $purchaseDate, $method, $matlUnit
        ) {
            // Lock material row → serialize MAC recalc untuk material yang sama
            $lockedMaterial = Material::withoutGlobalScopes()
                ->where('id', $material->id)
                ->lockForUpdate()
                ->first();

            $stockBefore = (float) $lockedMaterial->current_stock;
            $macBefore   = (float) $lockedMaterial->current_mac;

            // Recalc MAC (weighted avg)
            $stockAfter = $stockBefore + $qty;
            $macAfter = $stockAfter > 0
                ? round((($stockBefore * $macBefore) + ($qty * $unitPrice)) / $stockAfter, 4)
                : (float) $unitPrice;

            $purchase = MaterialPurchase::create([
                'company_id'     => $company->id,
                'purchase_number'=> $this->generatePurchaseNumber($company, $purchaseDate),
                'purchase_date'  => $purchaseDate,
                'vendor_id'      => $vendor?->id,
                'material_id'    => $material->id,
                'qty'            => $qty,
                'unit_price'     => $unitPrice,
                'total_amount'   => $total,
                'payment_method' => $method,
                'cash_account_id'=> $data['cash_account_id'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'bukti_tf_path'  => $data['bukti_tf_path'] ?? null,
                'created_by'     => Auth::id() ?? ($data['created_by'] ?? 1),
            ]);

            // Post journal
            $journal = $this->postPurchaseJournal($purchase, $company, $material, $vendor, $matlUnit, $method);
            $purchase->update(['journal_entry_id' => $journal->id]);

            // Update material stock + MAC (via direct DB — hindari observer loop)
            DB::table('materials')
                ->where('id', $material->id)
                ->update([
                    'current_stock' => $stockAfter,
                    'current_mac'   => $macAfter,
                    'updated_at'    => now(),
                ]);

            // Record stock movement (audit log)
            MaterialStockMovement::create([
                'company_id'   => $company->id,
                'material_id'  => $material->id,
                'movement_type'=> 'in',
                'source_type'  => MaterialPurchase::class,
                'source_id'    => $purchase->id,
                'qty_change'   => $qty,
                'unit_cost'    => $unitPrice,
                'stock_before' => $stockBefore,
                'stock_after'  => $stockAfter,
                'mac_before'   => $macBefore,
                'mac_after'    => $macAfter,
                'movement_date'=> $purchaseDate,
                'notes'        => 'Pembelian ' . $purchase->purchase_number,
                'created_by'   => Auth::id(),
            ]);

            return $purchase->refresh();
        });
    }

    private function postPurchaseJournal(
        MaterialPurchase $purchase,
        Company $company,
        Material $material,
        ?Vendor $vendor,
        ?BusinessUnit $matlUnit,
        string $method,
    ): JournalEntry {
        // Debit side: Persediaan Material (role: inventory_solar sebagai fallback,
        // atau kalau nanti ada role inventory_material khusus, pakai itu).
        // Sementara pakai kode 111220 (Persediaan) atau buat akun spesifik 111260.
        $accPersediaan = Account::findByRoleOrCode(
            \App\Enums\AccountRole::InventorySolar, // reuse inventory role
            '111260',                                 // atau 111220 sebagai fallback
            $company->id,
        );

        // Kalau tidak ada 111260, coba 111220 (persediaan solar sebagai fallback umum)
        if (! $accPersediaan) {
            $accPersediaan = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', '111220')
                ->postable()
                ->first();
        }

        if (! $accPersediaan) {
            throw ValidationException::withMessages([
                'material_id' => 'Akun Persediaan Material (111220 atau 111260) tidak ditemukan/postable. '
                    . 'Buka Master Data → Daftar Akun, buat akun persediaan lebih dulu.',
            ]);
        }

        // Credit side: Kas (tunai) atau Utang Vendor (kredit)
        if ($method === 'tunai') {
            if ($purchase->cash_account_id) {
                $accKredit = Account::withoutGlobalScopes()->find($purchase->cash_account_id);
                if (! $accKredit || ! $accKredit->isPostable()) {
                    throw ValidationException::withMessages([
                        'cash_account_id' => 'Akun Kas tidak valid atau bukan sub-akun postable.',
                    ]);
                }
            } else {
                $accKredit = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);
            }
            if (! $accKredit) {
                throw ValidationException::withMessages([
                    'cash_account_id' => 'Akun Kas tidak ditemukan. Pilih akun kas manual.',
                ]);
            }
            $creditDescription = 'Pembayaran tunai pembelian material';
        } else {
            // Kredit — pakai Utang Vendor
            $accKredit = Account::findByRoleOrCode(
                \App\Enums\AccountRole::PayableVendor,
                '221100',
                $company->id,
            );
            if (! $accKredit) {
                throw ValidationException::withMessages([
                    'vendor_id' => 'Akun Utang Vendor (221100) tidak ditemukan/postable. Buat akun ini di Daftar Akun.',
                ]);
            }
            $creditDescription = 'Utang pembelian dari ' . optional($vendor)->name;
        }

        $qtyLabel = rtrim(rtrim((string) $purchase->qty, '0'), '.');
        $description = 'Pembelian ' . $material->name
            . ' ' . $qtyLabel . ' ' . $material->satuan
            . ' @ Rp ' . number_format($purchase->unit_price, 0, ',', '.')
            . ($vendor ? ' — ' . $vendor->name : '');

        return $this->journalService->createEntryWithLines(
            company:          $company,
            date:             Carbon::parse($purchase->purchase_date),
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $purchase->purchase_date,
                'document_number'  => $purchase->purchase_number,
                'document_type'    => 'bkk',
                'business_unit_id' => optional($matlUnit)->id,
                'description'      => $description,
                'period_year'      => Carbon::parse($purchase->purchase_date)->year,
                'period_month'     => Carbon::parse($purchase->purchase_date)->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $purchase->created_by,
                'posted_by'        => Auth::id() ?? $purchase->created_by,
                'posted_at'        => now(),
                'total_amount'     => $purchase->total_amount,
            ],
            linesFactory: fn (JournalEntry $entry): array => [
                [
                    'account_id'  => $accPersediaan->id,
                    'description' => 'Persediaan ' . $material->name . ' +' . $qtyLabel . ' ' . $material->satuan,
                    'debit'       => $purchase->total_amount,
                    'kredit'      => 0,
                ],
                [
                    'account_id'  => $accKredit->id,
                    'description' => $creditDescription,
                    'debit'       => 0,
                    'kredit'      => $purchase->total_amount,
                ],
            ],
        );
    }
}
