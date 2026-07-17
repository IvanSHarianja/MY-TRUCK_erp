<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RentalLog;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\OperationalCostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-post jurnal beban operasional saat RentalLog dibuat/diubah/dihapus.
 *
 * Alur:
 *   - created  : hitung cost dari contract standard (atau override log), post
 *                 jurnal BBK dengan multi-line breakdown, simpan
 *                 journal_entry_id balik ke log.
 *   - updating : bila field yang mempengaruhi cost berubah, void jurnal lama
 *                 dan post yang baru (setelah update tersimpan → updated hook).
 *   - deleting : bila log punya jurnal posted, void dulu.
 *
 * Skip conditions (no jurnal):
 *   - Contract tidak include_bbm DAN tidak include_operator (alat_saja).
 *   - Total cost = 0 (jam_kerja=0 atau semua standar biaya kosong).
 *   - Log sudah punya journal_entry_id di created (defensive, double-fire).
 */
class RentalLogObserver
{
    public function __construct(
        private OperationalCostService $costService,
        private JournalService $journalService,
    ) {}

    public function created(RentalLog $log): void
    {
        if ($log->journal_entry_id) {
            return;
        }

        $this->postCostJournal($log);
    }

    public function updated(RentalLog $log): void
    {
        // Field yang mempengaruhi cost — kalau tidak berubah, skip repost.
        $costFields = [
            'jam_kerja', 'solar_liter', 'override_biaya',
            'uang_makan_operator', 'premi_operator',
        ];

        $changed = collect($costFields)->contains(fn ($f) => $log->wasChanged($f));
        if (! $changed) {
            return;
        }

        DB::transaction(function () use ($log) {
            $this->voidExistingJournal($log);
            // Reload biar journal_entry_id fresh (null setelah void)
            $log->refresh();
            $this->postCostJournal($log);
        });
    }

    public function deleting(RentalLog $log): void
    {
        $this->voidExistingJournal($log);
    }

    /**
     * Post jurnal breakdown biaya operasional log.
     */
    private function postCostJournal(RentalLog $log): void
    {
        $cost = $this->costService->computeRentalLogCost($log);
        if ($cost['total'] <= 0) {
            return;
        }

        $company = Company::withoutGlobalScopes()->find($log->company_id);
        if (! $company) return;

        // Business Unit RENT (fallback ke UMUM kalau tidak ada — seharusnya ada)
        $rentBu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'RENT')
            ->first();

        // Akun-akun yang dibutuhkan
        $accBbm   = Account::findPostableByCode('551100', $company->id);
        $accGaji  = Account::findPostableByCode('552200', $company->id);
        $accPremi = Account::findPostableByCode('551200', $company->id);
        $accKas   = Account::findPostableByCode('111100', $company->id);

        if (! $accKas) {
            Log::warning("RentalLogObserver: akun Kas (111100) tidak ditemukan/postable untuk company {$company->id}. Skip auto-post.");
            return;
        }

        $logDate = Carbon::parse($log->log_date);

        try {
            $this->journalService->assertPeriodOpen($company, $logDate->year, $logDate->month);
        } catch (\Throwable $e) {
            Log::info("RentalLogObserver: periode {$logDate->year}-{$logDate->month} closed. Skip auto-post log {$log->id}.");
            return;
        }

        $description = 'Biaya operasional Rental Log '
            . optional($log->rentalContract)->contract_number
            . ' — ' . $logDate->format('d/m/Y');

        $journal = $this->journalService->createEntryWithLines(
            company: $company,
            date: $logDate,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $logDate,
                'document_number'  => 'BBK-RL-' . $log->id,
                'document_type'    => 'bkk',
                'business_unit_id' => optional($rentBu)->id,
                'description'      => $description,
                'period_year'      => $logDate->year,
                'period_month'     => $logDate->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $log->created_by,
                'posted_by'        => Auth::id() ?? $log->created_by,
                'posted_at'        => now(),
                'total_amount'     => $cost['total'],
            ],
            linesFactory: function (JournalEntry $entry) use ($cost, $accBbm, $accGaji, $accPremi, $accKas, $log) {
                // Tag semua line BEBAN dengan asset_id dari log — untuk P&L per unit.
                // Sisi kredit (kas) tidak di-tag karena kas bukan cost line.
                $assetId = $log->asset_id;
                $prefix = $log->asset ? '[' . $log->asset->asset_code . '] ' : '';

                $lines = [];

                if ($cost['bbm'] > 0 && $accBbm) {
                    $lines[] = ['account_id' => $accBbm->id, 'asset_id' => $assetId, 'description' => $prefix . 'Beban BBM Solar', 'debit' => $cost['bbm'], 'kredit' => 0];
                }
                if ($cost['gaji'] > 0 && $accGaji) {
                    $lines[] = ['account_id' => $accGaji->id, 'asset_id' => $assetId, 'description' => $prefix . 'Gaji operator', 'debit' => $cost['gaji'], 'kredit' => 0];
                }
                if ($cost['makan'] > 0 && $accGaji) {
                    $lines[] = ['account_id' => $accGaji->id, 'asset_id' => $assetId, 'description' => $prefix . 'Tunjangan makan operator', 'debit' => $cost['makan'], 'kredit' => 0];
                }
                if ($cost['premi'] > 0 && $accPremi) {
                    $lines[] = ['account_id' => $accPremi->id, 'asset_id' => $assetId, 'description' => $prefix . 'Premi operator', 'debit' => $cost['premi'], 'kredit' => 0];
                }

                // Sisi kredit (Kas total) — tidak di-tag asset_id (kas bukan cost line).
                $lines[] = ['account_id' => $accKas->id, 'description' => 'Pembayaran biaya operasional rental', 'debit' => 0, 'kredit' => $cost['total']];

                return $lines;
            },
        );

        // Simpan link balik ke log tanpa memicu observer updated (menghindari infinite loop).
        RentalLog::withoutEvents(function () use ($log, $journal) {
            $log->update(['journal_entry_id' => $journal->id]);
        });
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
}
