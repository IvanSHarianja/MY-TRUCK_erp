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
                'project_addendum' => $this->voidProjectAddendum($invoice),
                default           => null,
            };
        });
    }

    /** Invoice pekerjaan tambah di-void → nilai tambah tidak lagi dihitung ke kontrak. */
    private function voidProjectAddendum(Invoice $invoice): void
    {
        \App\Models\ProjectAddendum::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'approved')
            ->update(['status' => 'void', 'updated_at' => now()]);
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

        // Lepas SEMUA log (termasuk log per M³/hari dengan rit 0) & ongkos gendong
        // supaya bisa ditagih ulang.
        RitLog::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->update(['invoice_id' => null]);

        \App\Models\ArmadaMobilization::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->update(['invoice_id' => null]);

        if ($unbilledBackCount > 0) {
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

        app(\App\Services\Accounting\ProjectService::class)
            ->rollbackTermin($termin, "Void invoice termin {$invoice->invoice_number}");
    }

    private function detachMaterialSale(Invoice $invoice): void
    {
        $sales = MaterialSale::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->get();

        foreach ($sales as $sale) {
            // Stok kembali, HPP & biaya kirim dibalik (lihat MaterialSaleService::reverseSale).
            app(\App\Services\Accounting\MaterialSaleService::class)
                ->reverseSale($sale, "Void penjualan {$sale->sale_number} (invoice {$invoice->invoice_number})");

            $sale->update(['invoice_id' => null]);
        }
    }
}
