<?php

namespace App\Observers;

use App\Models\ArmadaContract;
use App\Models\Invoice;
use App\Models\MaterialSale;
use App\Models\Project;
use App\Models\ProjectTermin;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    /**
     * Saat invoice di-void, rollback semua counter & relasi di sumbernya.
     * - ARMD: kurangi billed_rit, lepas rit_logs.invoice_id
     * - RENT: kurangi billed_jam, lepas rental_logs.invoice_id
     * - BONG: kurangi tertagih_pct, hapus project_termin record
     * - MATL: unlink material_sales.invoice_id (sale tetap exists)
     *
     * Rollback dibungkus DB::transaction supaya atomic — bila salah satu operasi
     * (update counter atau detach log) gagal, seluruh cascade batal. Penting bila
     * void ditrigger dari luar InvoiceService::void() (mis. Filament Edit langsung).
     * Nested transaction aman: kalau caller sudah dalam transaction, Laravel pakai
     * savepoint; kalau tidak, ini jadi transaction utama.
     */
    public function updated(Invoice $invoice): void
    {
        $becameVoid = $invoice->wasChanged('status') && $invoice->status === 'void';
        if (! $becameVoid) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            match ($invoice->source_type) {
                'armada_contract' => $this->rollbackArmada($invoice),
                'rental_contract' => $this->rollbackRental($invoice),
                'project_termin'  => $this->rollbackProjectTermin($invoice),
                'material_sale'   => $this->detachMaterialSale($invoice),
                default           => null,
            };
        });
    }

    private function rollbackArmada(Invoice $invoice): void
    {
        // BUG-23: lockForUpdate cegah race saat void concurrent
        $contract = ArmadaContract::withoutGlobalScopes()
            ->where('id', $invoice->source_id)
            ->lockForUpdate()
            ->first();
        if (! $contract) return;

        $unbilledBackCount = (int) RitLog::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->sum('rit_count');

        if ($unbilledBackCount > 0) {
            RitLog::withoutGlobalScopes()
                ->where('invoice_id', $invoice->id)
                ->update(['invoice_id' => null]);

            // Atomic decrement (bukan read-modify-write)
            ArmadaContract::withoutGlobalScopes()
                ->where('id', $contract->id)
                ->decrement('billed_rit', $unbilledBackCount);
        }
    }

    private function rollbackRental(Invoice $invoice): void
    {
        // BUG-23: lockForUpdate cegah race
        $contract = RentalContract::withoutGlobalScopes()
            ->where('id', $invoice->source_id)
            ->lockForUpdate()
            ->first();
        if (! $contract) return;

        $unbilledBackJam = (float) RentalLog::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->sum('jam_kerja');

        if ($unbilledBackJam > 0) {
            RentalLog::withoutGlobalScopes()
                ->where('invoice_id', $invoice->id)
                ->update(['invoice_id' => null]);

            RentalContract::withoutGlobalScopes()
                ->where('id', $contract->id)
                ->decrement('billed_jam', $unbilledBackJam);
        }
    }

    private function rollbackProjectTermin(Invoice $invoice): void
    {
        $termin = ProjectTermin::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->first();

        if (! $termin) return;

        // BUG-23: lockForUpdate
        $project = Project::withoutGlobalScopes()
            ->where('id', $termin->project_id)
            ->lockForUpdate()
            ->first();
        if ($project) {
            $newTertagih = max(0, round((float) $project->tertagih_pct - (float) $termin->termin_pct, 2));
            // BUG-24: reopen ke 'berjalan' kalau tertagih_pct < 100
            // (tidak peduli progress_pct — kalau sudah selesai tapi tertagih
            // turun, user perlu bisa tagih ulang termin baru).
            $newStatus = ($project->status === 'selesai' && $newTertagih < 100 - 0.005)
                ? 'berjalan'
                : $project->status;

            $project->update([
                'tertagih_pct' => $newTertagih,
                'status'       => $newStatus,
            ]);
        }

        // Hapus termin record (atau bisa pakai soft delete kalau mau audit)
        $termin->delete();
    }

    private function detachMaterialSale(Invoice $invoice): void
    {
        $sales = MaterialSale::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->get();

        foreach ($sales as $sale) {
            // BIZ-01: Restore stock kalau sale punya stock movement OUT.
            // Sales yang legacy (pre-BIZ01, tanpa stock movement) di-skip.
            $outMovement = \App\Models\MaterialStockMovement::withoutGlobalScopes()
                ->where('source_type', MaterialSale::class)
                ->where('source_id', $sale->id)
                ->where('movement_type', 'out')
                ->first();

            if ($outMovement) {
                $material = \App\Models\Material::withoutGlobalScopes()
                    ->where('id', $sale->material_id)
                    ->lockForUpdate()
                    ->first();

                if ($material) {
                    $stockBefore = (float) $material->current_stock;
                    $qtyRestore  = abs((float) $outMovement->qty_change);
                    $stockAfter  = $stockBefore + $qtyRestore;

                    \DB::table('materials')
                        ->where('id', $material->id)
                        ->update([
                            'current_stock' => $stockAfter,
                            // MAC tidak berubah — sale tidak affect MAC (hanya purchase)
                            'updated_at'    => now(),
                        ]);

                    // Audit trail: adjustment movement restore
                    \App\Models\MaterialStockMovement::create([
                        'company_id'   => $material->company_id,
                        'material_id'  => $material->id,
                        'movement_type'=> 'adjustment',
                        'source_type'  => MaterialSale::class,
                        'source_id'    => $sale->id,
                        'qty_change'   => $qtyRestore,
                        'unit_cost'    => (float) $outMovement->unit_cost,
                        'stock_before' => $stockBefore,
                        'stock_after'  => $stockAfter,
                        'mac_before'   => (float) $material->current_mac,
                        'mac_after'    => (float) $material->current_mac,
                        'movement_date'=> now()->toDateString(),
                        'notes'        => "Restore stock void sale {$sale->sale_number} (invoice {$invoice->invoice_number})",
                        'created_by'   => \Illuminate\Support\Facades\Auth::id(),
                    ]);
                }
            }

            $sale->update(['invoice_id' => null]);
        }
    }
}
