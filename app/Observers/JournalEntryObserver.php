<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\RentalLog;
use App\Models\RitLog;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cascade rollback saat JournalEntry di-void.
 *
 * `JournalService::void()` mengubah `status='void'` + buat jurnal pembalik,
 * TAPI tidak menyentuh counter aplikatif di source (project.dp_diterima,
 * log.journal_entry_id, dll). Observer ini menutup gap itu: mendeteksi
 * transisi status → 'void' dan rollback counter berdasar pola document_number.
 *
 * ATURAN: prefix document_number hanya dipakai sebagai ROUTER. Keputusan
 * cascade selalu lewat link struktural yang tidak bisa diketik user —
 * FK balik `{tabel}.journal_entry_id` atau `journal_entries.source_*`.
 * No. Bukti jurnal manual bebas diketik, jadi jurnal koreksi ber-No. Bukti
 * "DP-PR26-0003" atau "BBK-RL-12" tidak boleh menyentuh dokumen asli.
 *
 * Pattern yang di-handle:
 *   DP-{project_number}   → decrement Project.dp_diterima (source = Project)
 *   BBK-RL-{log_id}       → void DEPUSE log + nullify RentalLog.journal_entry_id
 *                            (log dicari via FK; log jadi "orphan", edit log
 *                            akan trigger observer repost)
 *   BBK-RT-{log_id}       → idem untuk RitLog
 *   BBK-MT-{log_id}       → nullify AssetMaintenanceLog.journal_entry_id (FK)
 *   PB*                   → rollback stock + MAC (MaterialPurchase via FK)
 *   document_type=invoice → sync Invoice.status (Invoice via FK)
 *   HPP-*                 → no-op (sale.journal_entry_id link ke revenue,
 *                            bukan ke HPP journal — tidak ada counter untuk
 *                            di-rollback)
 *   DEP-*                 → no-op (idempotency di DepreciationService
 *                            handle re-run bulan yang sama via status check)
 *
 * Update dilakukan via raw DB untuk hindari re-trigger event observer
 * (menghindari infinite recursion).
 */
class JournalEntryObserver
{
    public function updated(JournalEntry $entry): void
    {
        $becameVoid = $entry->wasChanged('status') && $entry->status === 'void';
        if (! $becameVoid) {
            return;
        }

        DB::transaction(function () use ($entry) {
            $docNum = (string) $entry->document_number;

            if (str_starts_with($docNum, 'DP-')) {
                $this->rollbackProjectDp($entry);
            } elseif (str_starts_with($docNum, 'BBK-RL-')) {
                $this->cascadeLogCostVoid($entry, RentalLog::class);
            } elseif (str_starts_with($docNum, 'BBK-RT-')) {
                $this->cascadeLogCostVoid($entry, RitLog::class);
            } elseif (str_starts_with($docNum, 'BBK-MT-')) {
                // Maintenance log tidak menghasilkan DEPUSE — no cascade.
                $this->nullifyLogJournal('asset_maintenance_logs', $entry->id);
            } elseif (str_starts_with($docNum, 'PB')) {
                // BIZ-01: Void jurnal Purchase → rollback stock + recompute MAC.
                // Kalau tidak, `materials.current_stock`/`current_mac` tidak match
                // dengan saldo Persediaan di Neraca.
                $this->cascadePurchaseVoid($entry);
            } elseif ($entry->document_type === 'invoice') {
                // Void jurnal invoice → sync Invoice.status='void'.
                // InvoiceObserver akan lanjut cascade ke source (termin decrement
                // tertagih_pct, rental billed_jam, dll).
                $this->cascadeInvoiceVoid($entry);
            }
            // HPP-*, DEP-*, quick_tx: no-op
        });
    }

    /**
     * Kurangi Project.dp_diterima berdasar total_amount jurnal void.
     * Project diambil dari source jurnal (diisi ProjectService::terimaDP),
     * bukan dari teks "DP-{project_number}" — jurnal manual tidak punya source.
     */
    private function rollbackProjectDp(JournalEntry $entry): void
    {
        if (! $entry->sourceIs(Project::class)) {
            Log::info("JournalEntryObserver: void {$entry->entry_number} ber-No. Bukti DP- tanpa source Project (jurnal manual) — skip rollback DP.");
            return;
        }

        $project = Project::withoutGlobalScopes()
            ->where('company_id', $entry->company_id)
            ->find($entry->source_id);

        if (! $project) {
            Log::info("JournalEntryObserver: DP void {$entry->entry_number} — project #{$entry->source_id} tidak ditemukan, skip.");
            return;
        }

        $newDp = max(0, (float) $project->dp_diterima - (float) $entry->total_amount);

        DB::table('projects')
            ->where('id', $project->id)
            ->update(['dp_diterima' => $newDp, 'updated_at' => now()]);
    }

    /**
     * Nullify journal_entry_id di log table (raw DB — hindari trigger observer log).
     * Log jadi orphan; user bisa edit log tersebut → observer log akan repost jurnal baru.
     */
    private function nullifyLogJournal(string $table, int $journalId): void
    {
        DB::table($table)
            ->where('journal_entry_id', $journalId)
            ->update(['journal_entry_id' => null, 'updated_at' => now()]);
    }

    /**
     * Void jurnal biaya operasional log (BBK-RL / BBK-RT).
     *
     * Log dicari via FK `journal_entry_id`, bukan dari angka di No. Bukti —
     * jurnal manual ber-No. Bukti "BBK-RL-12" tidak ter-link ke log mana pun,
     * jadi tidak ada yang di-cascade.
     *
     * @param  class-string<RentalLog|RitLog>  $logClass
     */
    private function cascadeLogCostVoid(JournalEntry $bbkEntry, string $logClass): void
    {
        $log = $logClass::withoutGlobalScopes()
            ->where('company_id', $bbkEntry->company_id)
            ->where('journal_entry_id', $bbkEntry->id)
            ->first();

        if (! $log) {
            return;
        }

        // BUG-DEPUSE-01: BBK void tanpa cascade DEPUSE bikin akumulasi
        // penyusutan usage-based overstate. Void jurnal DEPUSE dulu,
        // baru nullify link log.
        $this->cascadeVoidDepuseForLog($bbkEntry, $log);
        $this->nullifyLogJournal($log->getTable(), $bbkEntry->id);
    }

    /**
     * BUG-DEPUSE-01: Void semua jurnal DEPUSE milik log yang jurnal BBK-nya
     * sedang di-void.
     *
     * Kenapa perlu:
     * BBK-RL / BBK-RT adalah jurnal biaya operasional per log (BBM, uang jalan).
     * Log yang sama juga menghasilkan DEPUSE-{asset}-{log} kalau aset pakai method
     * usage-based (per_hour / per_rit). Kalau BBK di-void tapi DEPUSE tidak,
     * akumulasi penyusutan overstate — biaya usage sudah dibalik tapi penyusutan
     * usage-nya masih tercatat.
     *
     * DEPUSE dicari via source jurnal = log ini. Pola teks 'DEPUSE-%-{log_id}'
     * tidak dipakai lagi karena RentalLog dan RitLog ber-id sama saling tangkap.
     *
     * Strategi error: THROW kalau salah satu DEPUSE gagal di-void (mis. period
     * DEPUSE sudah closed). Silent-skip di sini bikin akumulasi overstate senyap.
     * Transaction outer akan rollback BBK void juga — user perlu buka period dulu.
     */
    private function cascadeVoidDepuseForLog(JournalEntry $bbkEntry, RentalLog|RitLog $log): void
    {
        $depuseJournals = app(DepreciationService::class)->postedUsageJournalsFor($log);

        if ($depuseJournals->isEmpty()) {
            return;
        }

        $journalService = app(JournalService::class);

        foreach ($depuseJournals as $depuse) {
            try {
                $journalService->void(
                    $depuse,
                    'Auto-cascade dari void ' . $bbkEntry->entry_number,
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    'Cascade void gagal: jurnal DEPUSE %s tidak bisa di-void saat void %s. '
                    . 'Detail: %s. '
                    . 'Kemungkinan periode DEPUSE sudah ditutup — buka periode dulu.',
                    $depuse->entry_number,
                    $bbkEntry->entry_number,
                    $e->getMessage(),
                ), 0, $e);
            }
        }
    }

    /**
     * Void jurnal invoice → sinkronkan status invoice ke 'void'.
     *
     * BUG-03 GUARD:
     *   Kalau invoice punya payment records, void jurnal DITOLAK (throw).
     *   Alasan: kalau invoice void tapi payment tetap ada, kas naik dari
     *   pembayaran ke piutang yang secara akuntansi sudah dibatalkan —
     *   situasi self-inconsistent yang butuh cleanup manual accountant.
     *   Solusi ke user: reverse payment dulu via PaymentService::reverse
     *   (yang balance-safe), baru void jurnal invoice.
     *
     * Update dilakukan via Eloquent (bukan raw DB) supaya InvoiceObserver.updated
     * ter-trigger dan cascade ke source_type (project_termin → decrement
     * tertagih_pct, rental_contract → decrement billed_jam, dll).
     *
     * Skip kalau invoice sudah void (idempotent — mis. dipicu 2x oleh
     * pembalik entry yang juga bertipe 'invoice' — meski jarang).
     */
    private function cascadeInvoiceVoid(JournalEntry $entry): void
    {
        $invoice = Invoice::withoutGlobalScopes()
            ->where('journal_entry_id', $entry->id)
            ->first();

        if (! $invoice) {
            return;
        }

        if ($invoice->status === 'void') {
            return;
        }

        // BUG-03: block cascade kalau ada payment aktif. Throw akan
        // rollback DB::transaction di observer → journal tidak jadi void.
        $paymentCount = $invoice->payments()->count();
        if ($paymentCount > 0) {
            throw new \RuntimeException(sprintf(
                'Tidak bisa void jurnal invoice %s: invoice ini masih memiliki %d payment aktif. '
                . 'Reverse semua payment terlebih dahulu (via halaman Payments) sebelum void jurnal invoice-nya.',
                $invoice->invoice_number,
                $paymentCount,
            ));
        }

        $invoice->update([
            'status'      => 'void',
            'voided_at'   => now(),
            'voided_by'   => Auth::id() ?? $invoice->created_by,
            'void_reason' => 'Auto-cascade dari void jurnal ' . $entry->entry_number,
        ]);
    }

    /**
     * BIZ-01: Cascade void jurnal Pembelian Material.
     *
     * Alur:
     * 1. Cari MaterialPurchase yang link ke journal ini
     * 2. Cari stock movement IN yang di-generate purchase → mark as void
     *    (record adjustment movement ber-qty negatif untuk audit history)
     * 3. Recompute current_stock & current_mac dari sisa movement history
     *
     * Kalau tidak, saldo Persediaan di Neraca (yang sudah net-off pembalik)
     * jadi tidak match dengan `materials.current_stock`.
     */
    private function cascadePurchaseVoid(JournalEntry $entry): void
    {
        $purchase = \App\Models\MaterialPurchase::withoutGlobalScopes()
            ->where('journal_entry_id', $entry->id)
            ->first();

        if (! $purchase) {
            \Log::info("JournalEntryObserver: Void PB {$entry->entry_number} — MaterialPurchase tidak ditemukan, skip cascade.");
            return;
        }

        $material = \App\Models\Material::withoutGlobalScopes()
            ->where('id', $purchase->material_id)
            ->lockForUpdate()
            ->first();

        if (! $material) {
            return;
        }

        // Rollback stock: kurangi qty purchase dari stock saat ini
        $stockBefore = (float) $material->current_stock;
        $stockAfter  = max(0, $stockBefore - (float) $purchase->qty);

        // Recompute MAC dari semua stock movement IN yang MASIH aktif
        // (exclude purchase yang barusan di-void).
        //
        // Weighted average of all remaining IN movements:
        // MAC = SUM(qty × unit_cost) / SUM(qty)
        $activeInMovements = \App\Models\MaterialStockMovement::withoutGlobalScopes()
            ->where('material_id', $material->id)
            ->where('movement_type', 'in')
            ->where(function ($q) use ($purchase) {
                // Exclude movement dari purchase yang barusan void
                $q->where('source_type', '!=', \App\Models\MaterialPurchase::class)
                  ->orWhere('source_id', '!=', $purchase->id);
            })
            ->get();

        $totalQty  = $activeInMovements->sum(fn ($m) => (float) $m->qty_change);
        $totalCost = $activeInMovements->sum(fn ($m) => (float) $m->qty_change * (float) $m->unit_cost);
        $macAfter  = $totalQty > 0 ? round($totalCost / $totalQty, 4) : 0.0;

        \DB::table('materials')
            ->where('id', $material->id)
            ->update([
                'current_stock' => $stockAfter,
                'current_mac'   => $macAfter,
                'updated_at'    => now(),
            ]);

        // Audit trail: record 'adjustment' movement untuk void
        \App\Models\MaterialStockMovement::create([
            'company_id'   => $material->company_id,
            'material_id'  => $material->id,
            'movement_type'=> 'adjustment',
            'source_type'  => \App\Models\MaterialPurchase::class,
            'source_id'    => $purchase->id,
            'qty_change'   => -1 * (float) $purchase->qty,
            'unit_cost'    => (float) $purchase->unit_price,
            'stock_before' => $stockBefore,
            'stock_after'  => $stockAfter,
            'mac_before'   => (float) $material->current_mac,
            'mac_after'    => $macAfter,
            'movement_date'=> now()->toDateString(),
            'notes'        => "Adjustment void PB {$purchase->purchase_number} (jurnal {$entry->entry_number})",
            'created_by'   => Auth::id(),
        ]);
    }
}
