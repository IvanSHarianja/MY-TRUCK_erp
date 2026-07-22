<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Project;
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
 * Pattern yang di-handle:
 *   DP-{project_number}   → decrement Project.dp_diterima
 *   BBK-RL-{log_id}       → nullify RentalLog.journal_entry_id (log jadi
 *                            "orphan"; edit log akan trigger observer repost)
 *   BBK-RT-{log_id}       → nullify RitLog.journal_entry_id
 *   BBK-MT-{log_id}       → nullify AssetMaintenanceLog.journal_entry_id
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
                $this->nullifyLogJournal('rental_logs', $entry->id);
            } elseif (str_starts_with($docNum, 'BBK-RT-')) {
                $this->nullifyLogJournal('rit_logs', $entry->id);
            } elseif (str_starts_with($docNum, 'BBK-MT-')) {
                $this->nullifyLogJournal('asset_maintenance_logs', $entry->id);
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
     * project_number di-extract dari document_number "DP-{project_number}".
     */
    private function rollbackProjectDp(JournalEntry $entry): void
    {
        $projectNumber = substr($entry->document_number, 3); // strip "DP-"

        $project = Project::withoutGlobalScopes()
            ->where('company_id', $entry->company_id)
            ->where('project_number', $projectNumber)
            ->first();

        if (! $project) {
            Log::info("JournalEntryObserver: DP void {$entry->entry_number} — project {$projectNumber} tidak ditemukan, skip.");
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
}
