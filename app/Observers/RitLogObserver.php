<?php

namespace App\Observers;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\RitLog;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jurnal otomatis dari RitLog (ARMD):
 *   - DEPUSE penyusutan per rit (aset method=per_rit), ikut berubah saat log diubah/dihapus.
 *   - Biaya operasional TIDAK lagi dijurnal (sejak 2026-11-20) — dicatat keuangan
 *     sebesar yang benar-benar dibayar. Jurnal lama "BBK-RT-{log}" (biaya standar)
 *     tetap ada dan ikut di-void saat lognya dihapus.
 */
class RitLogObserver
{
    public function __construct(
        private JournalService $journalService,
        private DepreciationService $depreciationService,
    ) {}

    public function created(RitLog $log): void
    {
        // Biaya operasional (BBM, gaji, uang jalan) TIDAK dijurnal dari log:
        // Laba Rugi memakai biaya aktual yang dibayar keuangan (menu Transaksi
        // & Beban, ditandai unitnya). Tarif biaya di kontrak hanya standar
        // pembanding — lihat laporan Biaya Standar vs Aktual. Keputusan 2026-11-20.

        // BIZ-03: DEPUSE penyusutan usage-based (aset method=per_rit)
        $this->postDepreciationJournal($log);
    }

    public function updated(RitLog $log): void
    {
        // BIZ-03: DEPUSE bergantung pada rit_count, asset_id, log_date.
        $depFields = ['rit_count', 'asset_id', 'log_date'];
        if (! collect($depFields)->contains(fn ($f) => $log->wasChanged($f))) {
            return;
        }

        DB::transaction(function () use ($log) {
            $this->voidDepreciationJournalsForLog($log);
            $log->refresh();
            $this->postDepreciationJournal($log);
        });
    }

    public function deleting(RitLog $log): void
    {
        $this->voidExistingJournal($log);
        $this->voidDepreciationJournalsForLog($log);
    }

    private function voidExistingJournal(RitLog $log): void
    {
        if (! $log->journal_entry_id) {
            return;
        }

        $journal = JournalEntry::withoutGlobalScopes()->find($log->journal_entry_id);
        if (! $journal || ! $journal->isPosted()) {
            return;
        }

        try {
            $this->journalService->void($journal, 'Auto-void: RitLog ' . $log->id . ' diubah/dihapus');
        } catch (\Throwable $e) {
            Log::warning("RitLogObserver: gagal void jurnal {$journal->entry_number}: {$e->getMessage()}");
            return;
        }

        RitLog::withoutEvents(function () use ($log) {
            $log->update(['journal_entry_id' => null]);
        });
    }

    /**
     * BIZ-03: Post DEPUSE-{asset}-{log_id} jurnal penyusutan usage-based
     * untuk RitLog (method=per_rit). Method lain di-skip (per_hour dari
     * RentalLog, per_day belum di-wire).
     */
    private function postDepreciationJournal(RitLog $log): void
    {
        if (! $log->asset_id) {
            return;
        }

        $asset = Asset::withoutGlobalScopes()->find($log->asset_id);
        if (! $asset) {
            return;
        }

        $method = $asset->depreciation_method;
        if ($method !== DepreciationMethod::PerRit) {
            return;
        }

        $usage = (float) $log->rit_count;
        if ($usage <= 0) {
            return;
        }

        $documentNumber = sprintf('DEPUSE-%d-%d', $asset->id, $log->id);
        $logDate = Carbon::parse($log->log_date);

        $this->depreciationService->postUsageDepreciation(
            asset: $asset,
            usage: $usage,
            date: $logDate,
            documentNumber: $documentNumber,
            context: 'RitLog #' . $log->id
                . (optional($log->armadaContract)->contract_number
                    ? ' / ' . $log->armadaContract->contract_number
                    : ''),
            source: $log,
        );
    }

    /**
     * BIZ-03: Void semua jurnal DEPUSE untuk log ini. Lookup via source jurnal —
     * mencakup edit asset_id dan tidak menangkap DEPUSE milik RentalLog ber-id sama.
     */
    private function voidDepreciationJournalsForLog(RitLog $log): void
    {
        $journals = $this->depreciationService->postedUsageJournalsFor($log);

        foreach ($journals as $journal) {
            try {
                $this->journalService->void(
                    $journal,
                    'Auto-void: RitLog ' . $log->id . ' diubah/dihapus',
                );
            } catch (\Throwable $e) {
                Log::warning("RitLogObserver: gagal void DEPUSE {$journal->entry_number}: {$e->getMessage()}");
            }
        }
    }
}
