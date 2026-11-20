<?php

namespace App\Observers;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\RentalLog;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jurnal otomatis dari RentalLog (RENT):
 *   - DEPUSE penyusutan per jam (aset method=per_hour), ikut berubah saat log diubah/dihapus.
 *   - Biaya operasional TIDAK lagi dijurnal (sejak 2026-11-20) — dicatat keuangan
 *     sebesar yang benar-benar dibayar. Jurnal lama "BBK-RL-{log}" (biaya standar)
 *     tetap ada dan ikut di-void saat lognya dihapus.
 */
class RentalLogObserver
{
    public function __construct(
        private JournalService $journalService,
        private DepreciationService $depreciationService,
    ) {}

    public function created(RentalLog $log): void
    {
        // Biaya operasional (BBM, gaji, premi) TIDAK dijurnal dari log:
        // Laba Rugi memakai biaya aktual yang dibayar keuangan (menu Transaksi
        // & Beban, ditandai unitnya). Tarif biaya di kontrak hanya standar
        // pembanding — lihat laporan Biaya Standar vs Aktual. Keputusan 2026-11-20.

        // BIZ-03: DEPUSE penyusutan usage-based (aset method=per_hour)
        $this->postDepreciationJournal($log);
    }

    public function updated(RentalLog $log): void
    {
        // BIZ-03: DEPUSE bergantung pada jam_kerja, asset_id, log_date.
        $depFields = ['jam_kerja', 'asset_id', 'log_date'];
        if (! collect($depFields)->contains(fn ($f) => $log->wasChanged($f))) {
            return;
        }

        DB::transaction(function () use ($log) {
            $this->voidDepreciationJournalsForLog($log);
            $log->refresh();
            $this->postDepreciationJournal($log);
        });
    }

    public function deleting(RentalLog $log): void
    {
        $this->voidExistingJournal($log);
        $this->voidDepreciationJournalsForLog($log);
    }

    /**
     * Void jurnal terkait (kalau ada & masih posted).
     */
    private function voidExistingJournal(RentalLog $log): void
    {
        if (! $log->journal_entry_id) {
            return;
        }

        $journal = JournalEntry::withoutGlobalScopes()->find($log->journal_entry_id);
        if (! $journal || ! $journal->isPosted()) {
            return;
        }

        try {
            $this->journalService->void($journal, 'Auto-void: RentalLog ' . $log->id . ' diubah/dihapus');
        } catch (\Throwable $e) {
            Log::warning("RentalLogObserver: gagal void jurnal {$journal->entry_number}: {$e->getMessage()}");
            return;
        }

        RentalLog::withoutEvents(function () use ($log) {
            $log->update(['journal_entry_id' => null]);
        });
    }

    /**
     * BIZ-03: Post DEPUSE-{asset}-{log_id} jurnal penyusutan usage-based.
     *
     * Trigger: aset method = per_hour. Aset method per_rit dan per_day tidak
     * dihandle di sini:
     *   - per_rit dipicu dari RitLogObserver
     *   - per_day belum di-wire (menunggu keputusan Q5 tentang field hari_kerja
     *     atau konversi jam→hari). Log info supaya kelihatan.
     */
    private function postDepreciationJournal(RentalLog $log): void
    {
        if (! $log->asset_id) {
            return;
        }

        $asset = Asset::withoutGlobalScopes()->find($log->asset_id);
        if (! $asset) {
            return;
        }

        $method = $asset->depreciation_method;
        if (! $method instanceof DepreciationMethod) {
            return;
        }

        if ($method === DepreciationMethod::PerDay) {
            Log::info(sprintf(
                'RentalLogObserver: asset %s method=per_day belum di-trigger otomatis. Log %d di-skip untuk DEPUSE.',
                $asset->asset_code, $log->id,
            ));
            return;
        }

        // Hanya per_hour yang dipicu dari RentalLog
        if ($method !== DepreciationMethod::PerHour) {
            return;
        }

        $usage = (float) $log->jam_kerja;
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
            context: 'RentalLog #' . $log->id
                . (optional($log->rentalContract)->contract_number
                    ? ' / ' . $log->rentalContract->contract_number
                    : ''),
            source: $log,
        );
    }

    /**
     * BIZ-03: Void semua jurnal DEPUSE untuk log ini.
     *
     * Lookup via source jurnal (bukan pola teks) — mencakup kasus edit yang
     * mengganti asset_id, dan tidak ikut menangkap DEPUSE milik RitLog dengan
     * id yang sama. Lihat DepreciationService::postedUsageJournalsFor().
     */
    private function voidDepreciationJournalsForLog(RentalLog $log): void
    {
        $journals = $this->depreciationService->postedUsageJournalsFor($log);

        foreach ($journals as $journal) {
            try {
                $this->journalService->void(
                    $journal,
                    'Auto-void: RentalLog ' . $log->id . ' diubah/dihapus',
                );
            } catch (\Throwable $e) {
                Log::warning("RentalLogObserver: gagal void DEPUSE {$journal->entry_number}: {$e->getMessage()}");
            }
        }
    }
}
