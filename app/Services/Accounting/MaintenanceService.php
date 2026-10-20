<?php

namespace App\Services\Accounting;

use App\Enums\MaintenanceType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\AssetMaintenanceLogItem;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialStockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Service pencatatan pemeliharaan aset + auto-post jurnal beban maintenance.
 *
 * DUA KOMPONEN BIAYA:
 *   1. `cost`     — biaya jasa/montir yang dibayar tunai saat itu.
 *   2. sparepart  — diambil dari stok (materials berjenis 'sparepart'),
 *                   dinilai dengan MAC yang dibekukan per baris.
 *
 * Kolom `cost` sengaja TETAP berarti "yang dibayar tunai" (bukan diubah jadi
 * total) supaya data lama yang tidak punya baris sparepart nilainya tidak
 * bergeser. Total biaya servis = AssetMaintenanceLog::totalCost().
 *
 * JURNAL (baris nol tidak ditulis):
 *   Dr Beban Maintenance 551400   cost + nilai sparepart   (ditag asset_id)
 *     Cr Kas 111100               cost
 *     Cr Persediaan Sparepart     nilai sparepart
 *
 * STOK: pemakaian sparepart mengurangi stok + mencatat MaterialStockMovement
 * 'out'. Edit/hapus log mengembalikan stok lewat movement 'adjustment'.
 *
 * BEDA PENANGANAN AKUN HILANG:
 *   - Tanpa sparepart (alur lama): jurnal di-skip + Log::warning, log tetap
 *     tersimpan. Perilaku ini dipertahankan agar data lama tidak berubah.
 *   - Dengan sparepart: THROW. Stok sudah berkurang, jadi jurnal tidak boleh
 *     hilang diam-diam — seluruh transaksi di-rollback.
 */
class MaintenanceService
{
    public function __construct(private JournalService $journalService) {}

    /**
     * Catat 1 record maintenance + pemakaian sparepart + auto-post jurnal.
     *
     * @param array{
     *     asset_id: int,
     *     maintenance_date: string|CarbonInterface,
     *     type: string|MaintenanceType,
     *     description: string,
     *     cost: float|int|string,
     *     vendor_id?: int|null,
     *     hm_saat_service?: float|null,
     *     next_service_hm?: float|null,
     *     next_service_date?: string|CarbonInterface|null,
     *     photo_url?: string|null,
     *     notes?: string|null,
     *     items?: array<int, array{material_id: int|string, qty: float|int|string}>,
     * } $data
     */
    public function log(array $data): AssetMaintenanceLog
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($data['asset_id']);
        $company = Company::withoutGlobalScopes()->findOrFail($asset->company_id);
        $maintenanceDate = Carbon::parse($data['maintenance_date']);
        $items = $this->normalizeItems($data['items'] ?? [], $company);

        // Pemakaian sparepart mengubah stok — periode wajib terbuka SEBELUM
        // stok disentuh, supaya tidak ada stok berkurang tanpa jurnal.
        if ($items) {
            $this->journalService->assertPeriodOpen($company, $maintenanceDate->year, $maintenanceDate->month);
        }

        return DB::transaction(function () use ($data, $asset, $company, $maintenanceDate, $items) {
            $log = AssetMaintenanceLog::create([
                'company_id'        => $company->id,
                'asset_id'          => $asset->id,
                'maintenance_date'  => $maintenanceDate,
                'type'              => $data['type'] instanceof MaintenanceType
                    ? $data['type']->value
                    : $data['type'],
                'description'       => $data['description'],
                'vendor_id'         => $data['vendor_id'] ?? null,
                'cost'              => (float) $data['cost'],
                'hm_saat_service'   => $data['hm_saat_service'] ?? null,
                'next_service_hm'   => $data['next_service_hm'] ?? null,
                'next_service_date' => isset($data['next_service_date'])
                    ? Carbon::parse($data['next_service_date'])
                    : null,
                'photo_url'         => $data['photo_url'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => Auth::id(),
            ]);

            $this->consumeSpareparts($log, $items);
            $log->load('items');

            if ($log->totalCost() > 0) {
                $this->postExpenseJournal($log, $asset, $company);
                $log->refresh();
            }

            return $log;
        });
    }

    /**
     * Ubah log + daftar sparepart-nya.
     *
     * Dipakai EditAction supaya perubahan sparepart ikut menyesuaikan stok.
     * Update dilakukan tanpa event — jurnal & stok ditangani eksplisit di sini,
     * kalau observer ikut jalan jurnal akan di-void/post dua kali.
     */
    public function updateLog(AssetMaintenanceLog $log, array $data): AssetMaintenanceLog
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($log->asset_id);
        $company = Company::withoutGlobalScopes()->findOrFail($log->company_id);
        $maintenanceDate = Carbon::parse($data['maintenance_date'] ?? $log->maintenance_date);
        $items = $this->normalizeItems($data['items'] ?? [], $company);

        if ($items || $log->items()->exists()) {
            $this->journalService->assertPeriodOpen($company, $maintenanceDate->year, $maintenanceDate->month);
        }

        return DB::transaction(function () use ($log, $data, $asset, $company, $maintenanceDate, $items) {
            $this->voidExistingJournal($log);
            $this->restoreSpareparts($log);

            AssetMaintenanceLog::withoutEvents(function () use ($log, $data, $maintenanceDate) {
                $log->update([
                    'maintenance_date'  => $maintenanceDate,
                    'type'              => $data['type'] instanceof MaintenanceType
                        ? $data['type']->value
                        : ($data['type'] ?? $log->type),
                    'description'       => $data['description'] ?? $log->description,
                    'vendor_id'         => $data['vendor_id'] ?? null,
                    'cost'              => (float) ($data['cost'] ?? 0),
                    'hm_saat_service'   => $data['hm_saat_service'] ?? null,
                    'next_service_hm'   => $data['next_service_hm'] ?? null,
                    'next_service_date' => isset($data['next_service_date'])
                        ? Carbon::parse($data['next_service_date'])
                        : null,
                    'photo_url'         => $data['photo_url'] ?? $log->photo_url,
                    'notes'             => $data['notes'] ?? null,
                ]);
            });

            $log->refresh();
            $this->consumeSpareparts($log, $items);
            $log->load('items');

            if ($log->totalCost() > 0) {
                $this->postExpenseJournal($log, $asset, $company);
                $log->refresh();
            }

            return $log;
        });
    }

    /**
     * Post jurnal beban maintenance (jasa tunai + sparepart dari stok).
     * Dipanggil dari log(), updateLog(), dan observer saat field biasa diedit.
     */
    public function postExpenseJournal(
        AssetMaintenanceLog $log,
        Asset $asset,
        Company $company,
    ): void {
        $log->loadMissing('items.material');

        $cost = (float) $log->cost;
        $sparepartLines = $this->sparepartCreditLines($log, $company);
        $sparepartTotal = array_sum(array_column($sparepartLines, 'amount'));
        $total = round($cost + $sparepartTotal, 2);

        if ($total <= 0) {
            return;
        }

        $buCode = $asset->defaultBusinessUnitCode();
        $bu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $buCode)
            ->first();

        // Sprint 2.5: role-based lookup dengan fallback code
        $accBeban = Account::findByRoleOrCode(\App\Enums\AccountRole::CogsMaintenance, '551400', $company->id);
        $accKas   = $cost > 0
            ? Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id)
            : null;

        if (! $accBeban || ($cost > 0 && ! $accKas)) {
            // Dengan sparepart: stok sudah berkurang di transaksi yang sama —
            // jurnal tidak boleh hilang diam-diam.
            if ($sparepartLines) {
                throw ValidationException::withMessages([
                    'cost' => 'Akun Beban Maintenance (551400) atau Kas (111100) tidak ditemukan/postable. '
                        . 'Lengkapi Daftar Akun dulu — lihat Panduan → Paket Akun per Fitur.',
                ]);
            }

            Log::warning("MaintenanceService: akun 551400 atau 111100 tidak ditemukan/postable untuk company {$company->id}. Skip auto-post log {$log->id}.");

            return;
        }

        $date = Carbon::parse($log->maintenance_date);

        try {
            $this->journalService->assertPeriodOpen($company, $date->year, $date->month);
        } catch (\Throwable $e) {
            if ($sparepartLines) {
                throw $e;
            }

            Log::info("MaintenanceService: periode {$date->year}-{$date->month} closed. Skip auto-post log {$log->id}.");

            return;
        }

        $vendorName = optional($log->vendor)->name;
        $description = 'Pemeliharaan ' . optional($asset)->asset_code
            . ' — ' . ($log->type instanceof MaintenanceType ? $log->type->label() : $log->type)
            . ($vendorName ? ' (vendor: ' . $vendorName . ')' : '');

        $journal = $this->journalService->createEntryWithLines(
            company: $company,
            date: $date,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $date,
                'document_number'  => 'BBK-MT-' . $log->id,
                'document_type'    => 'bkk',
                'business_unit_id' => optional($bu)->id,
                'description'      => $description,
                'period_year'      => $date->year,
                'period_month'     => $date->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $log->created_by,
                'posted_by'        => Auth::id() ?? $log->created_by,
                'posted_at'        => now(),
                'total_amount'     => $total,
            ],
            linesFactory: function (JournalEntry $entry) use ($accBeban, $accKas, $asset, $log, $cost, $total, $sparepartLines): array {
                $lines = [[
                    'account_id'  => $accBeban->id,
                    'asset_id'    => $asset->id,     // tag untuk P&L per unit
                    'description' => '[' . $asset->asset_code . '] ' . $log->description,
                    'debit'       => $total,
                    'kredit'      => 0,
                ]];

                if ($cost > 0) {
                    $lines[] = [
                        'account_id'  => $accKas->id,
                        // asset_id di sisi kas: null (kas tidak related aset spesifik)
                        'description' => 'Pembayaran jasa maintenance ' . optional($asset)->asset_code,
                        'debit'       => 0,
                        'kredit'      => $cost,
                    ];
                }

                foreach ($sparepartLines as $line) {
                    $lines[] = [
                        'account_id'  => $line['account_id'],
                        'description' => 'Pemakaian sparepart: ' . $line['description'],
                        'debit'       => 0,
                        'kredit'      => $line['amount'],
                    ];
                }

                return $lines;
            },
        );

        AssetMaintenanceLog::withoutEvents(function () use ($log, $journal) {
            $log->update(['journal_entry_id' => $journal->id]);
        });
    }

    /**
     * Void jurnal terkait log (dipanggil observer saat log dihapus/diubah).
     */
    public function voidExistingJournal(AssetMaintenanceLog $log): void
    {
        if (! $log->journal_entry_id) {
            return;
        }

        $journal = JournalEntry::withoutGlobalScopes()->find($log->journal_entry_id);
        if (! $journal || ! $journal->isPosted()) {
            return;
        }

        try {
            $this->journalService->void($journal, 'Auto-void: MaintenanceLog ' . $log->id . ' diubah/dihapus');
        } catch (\Throwable $e) {
            Log::warning("MaintenanceService: gagal void jurnal {$journal->entry_number}: {$e->getMessage()}");

            return;
        }

        AssetMaintenanceLog::withoutEvents(function () use ($log) {
            $log->update(['journal_entry_id' => null]);
        });
    }

    /**
     * Kembalikan stok sparepart milik log ini, lalu hapus barisnya.
     * Dipanggil saat log diedit (sebelum pemakaian baru dicatat) atau dihapus.
     */
    public function restoreSpareparts(AssetMaintenanceLog $log): void
    {
        $items = $log->items()->with('material')->get();

        foreach ($items as $item) {
            $material = Material::withoutGlobalScopes()
                ->where('id', $item->material_id)
                ->lockForUpdate()
                ->first();

            if ($material) {
                $stockBefore = (float) $material->current_stock;
                $qty         = (float) $item->qty;
                $stockAfter  = $stockBefore + $qty;

                DB::table('materials')
                    ->where('id', $material->id)
                    ->update(['current_stock' => $stockAfter, 'updated_at' => now()]);

                // MAC tidak berubah — pemakaian tidak pernah mengubah MAC,
                // jadi pengembaliannya pun tidak.
                MaterialStockMovement::create([
                    'company_id'    => $material->company_id,
                    'material_id'   => $material->id,
                    'movement_type' => 'adjustment',
                    'source_type'   => AssetMaintenanceLog::class,
                    'source_id'     => $log->id,
                    'qty_change'    => $qty,
                    'unit_cost'     => (float) $item->unit_cost,
                    'stock_before'  => $stockBefore,
                    'stock_after'   => $stockAfter,
                    'mac_before'    => (float) $material->current_mac,
                    'mac_after'     => (float) $material->current_mac,
                    'movement_date' => now()->toDateString(),
                    'notes'         => "Restore sparepart dari pemeliharaan #{$log->id}",
                    'created_by'    => Auth::id(),
                ]);
            }

            $item->delete();
        }
    }

    /**
     * Catat pemakaian sparepart: kurangi stok, bekukan MAC, catat movement.
     *
     * @param array<int, array{material_id: int, qty: float}> $items
     */
    private function consumeSpareparts(AssetMaintenanceLog $log, array $items): void
    {
        foreach ($items as $row) {
            $material = Material::withoutGlobalScopes()
                ->where('id', $row['material_id'])
                ->lockForUpdate()
                ->first();

            if (! $material) {
                throw ValidationException::withMessages([
                    'items' => 'Sparepart tidak ditemukan.',
                ]);
            }

            $stockBefore = (float) $material->current_stock;
            $qty         = (float) $row['qty'];

            // Keputusan 2026-10: stok kurang → TOLAK (konsisten dengan penjualan
            // material). Input pembeliannya dulu, baru catat servis.
            if ($qty > $stockBefore) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        'Stok sparepart [%s] %s tidak cukup. Tersedia: %s %s, dipakai: %s %s. '
                        . 'Catat pembeliannya dulu di Master Data → Pembelian Material.',
                        $material->code,
                        $material->name,
                        $this->trimNumber($stockBefore),
                        $material->satuan,
                        $this->trimNumber($qty),
                        $material->satuan,
                    ),
                ]);
            }

            $unitCost   = (float) $material->current_mac;
            $stockAfter = $stockBefore - $qty;

            if ($unitCost <= 0) {
                Log::warning(sprintf(
                    'MaintenanceService: sparepart [%s] %s dipakai di log %d dengan MAC 0 — '
                    . 'biaya servis understate. Stok awal kemungkinan diinput tanpa nilai.',
                    $material->code,
                    $material->name,
                    $log->id,
                ));
            }

            AssetMaintenanceLogItem::create([
                'company_id'               => $log->company_id,
                'asset_maintenance_log_id' => $log->id,
                'material_id'              => $material->id,
                'qty'                      => $qty,
                'unit_cost'                => $unitCost,
                'total'                    => round($qty * $unitCost, 2),
            ]);

            DB::table('materials')
                ->where('id', $material->id)
                ->update(['current_stock' => $stockAfter, 'updated_at' => now()]);

            MaterialStockMovement::create([
                'company_id'    => $material->company_id,
                'material_id'   => $material->id,
                'movement_type' => 'out',
                'source_type'   => AssetMaintenanceLog::class,
                'source_id'     => $log->id,
                'qty_change'    => -1 * $qty,
                'unit_cost'     => $unitCost,
                'stock_before'  => $stockBefore,
                'stock_after'   => $stockAfter,
                'mac_before'    => (float) $material->current_mac,
                'mac_after'     => (float) $material->current_mac,
                'movement_date' => Carbon::parse($log->maintenance_date)->toDateString(),
                'notes'         => "Pemakaian sparepart pemeliharaan #{$log->id}",
                'created_by'    => Auth::id(),
            ]);
        }
    }

    /**
     * Bersihkan & validasi input sparepart dari form.
     * Baris kosong dibuang; material yang sama digabung qty-nya.
     *
     * @return array<int, array{material_id: int, qty: float}>
     */
    private function normalizeItems(array $raw, Company $company): array
    {
        $merged = [];

        foreach ($raw as $row) {
            $materialId = (int) ($row['material_id'] ?? 0);
            $qty        = (float) ($row['qty'] ?? 0);

            if ($materialId <= 0 || $qty <= 0) {
                continue;   // baris repeater yang belum diisi
            }

            $merged[$materialId] = ($merged[$materialId] ?? 0) + $qty;
        }

        if (! $merged) {
            return [];
        }

        $materials = Material::withoutGlobalScopes()
            ->whereIn('id', array_keys($merged))
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($merged as $materialId => $qty) {
            $material = $materials->get($materialId);

            // Guard tenant + jenis di service, bukan cuma filter dropdown.
            if (! $material || $material->company_id !== $company->id) {
                throw ValidationException::withMessages([
                    'items' => 'Sparepart tidak ditemukan di PT ini.',
                ]);
            }

            if (! $material->isSparepart()) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        '[%s] %s bukan Sparepart — material dagangan dijual lewat menu Penjualan Material.',
                        $material->code,
                        $material->name,
                    ),
                ]);
            }

            $items[] = ['material_id' => $materialId, 'qty' => $qty];
        }

        return $items;
    }

    /**
     * Baris kredit persediaan, dikelompokkan per akun.
     * Normalnya satu akun (111270), tapi dikelompokkan agar tetap benar bila
     * ke depan sparepart dipetakan ke akun berbeda.
     *
     * @return array<int, array{account_id: int, amount: float, description: string}>
     */
    private function sparepartCreditLines(AssetMaintenanceLog $log, Company $company): array
    {
        $grouped = [];

        foreach ($log->items as $item) {
            $amount = (float) $item->total;
            if ($amount <= 0) {
                continue;
            }

            $material = $item->material;
            if (! $material) {
                continue;
            }

            $account = Account::inventoryAccountFor($material);

            if (! $account) {
                throw ValidationException::withMessages([
                    'items' => 'Akun Persediaan Sparepart tidak ditemukan/postable. '
                        . 'Buat akun dengan Peran "Persediaan Sparepart" (kode standar 111270) '
                        . 'di Master Data → Daftar Akun.',
                ]);
            }

            if (! isset($grouped[$account->id])) {
                $grouped[$account->id] = ['account_id' => $account->id, 'amount' => 0.0, 'names' => []];
            }

            $grouped[$account->id]['amount'] += $amount;
            $grouped[$account->id]['names'][] = $material->name;
        }

        return array_values(array_map(
            fn (array $g): array => [
                'account_id'  => $g['account_id'],
                'amount'      => round($g['amount'], 2),
                'description' => implode(', ', array_unique($g['names'])),
            ],
            $grouped,
        ));
    }

    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
