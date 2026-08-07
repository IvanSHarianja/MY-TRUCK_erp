<?php

namespace App\Observers;

use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RentalLog;
use App\Services\Accounting\DepreciationService;
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
        private DepreciationService $depreciationService,
    ) {}

    public function created(RentalLog $log): void
    {
        // BBK biaya operasional (existing behavior)
        if (! $log->journal_entry_id) {
            $this->postCostJournal($log);
        }

        // BIZ-03: DEPUSE penyusutan usage-based (aset method=per_hour)
        $this->postDepreciationJournal($log);
    }

    public function updated(RentalLog $log): void
    {
        // Field yang mempengaruhi cost — kalau tidak berubah, skip repost BBK.
        // MEDIUM-2: asset_id juga trigger repost supaya BBK re-tag ke asset baru
        // (amount BBK tidak berubah kalau jam sama, tapi asset_id tag di lines
        // harus ikut asset baru — kalau tidak, laporan per-unit salah tag).
        $costFields = [
            'jam_kerja', 'solar_liter', 'override_biaya',
            'uang_makan_operator', 'premi_operator',
            'asset_id',
        ];
        $costChanged = collect($costFields)->contains(fn ($f) => $log->wasChanged($f));

        // BIZ-03: DEPUSE bergantung pada jam_kerja, asset_id, log_date.
        $depFields = ['jam_kerja', 'asset_id', 'log_date'];
        $depChanged = collect($depFields)->contains(fn ($f) => $log->wasChanged($f));

        if (! $costChanged && ! $depChanged) {
            return;
        }

        DB::transaction(function () use ($log, $costChanged, $depChanged) {
            if ($costChanged) {
                $this->voidExistingJournal($log);
            }
            if ($depChanged) {
                $this->voidDepreciationJournalsForLog($log);
            }

            // Reload biar journal_entry_id + field lain fresh
            $log->refresh();

            if ($costChanged) {
                $this->postCostJournal($log);
            }
            if ($depChanged) {
                $this->postDepreciationJournal($log);
            }
        });
    }

    public function deleting(RentalLog $log): void
    {
        $this->voidExistingJournal($log);
        $this->voidDepreciationJournalsForLog($log);
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
        // Sprint 2.5: role-based lookup dengan fallback code
        $accBbm   = Account::findByRoleOrCode(\App\Enums\AccountRole::CogsBbm, '551100', $company->id);
        $accGaji  = Account::findByRoleOrCode(\App\Enums\AccountRole::OpexGaji, '552200', $company->id);
        $accPremi = Account::findByRoleOrCode(\App\Enums\AccountRole::CogsPremiUangJalan, '551200', $company->id);
        $accKas   = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);

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
        );
    }

    /**
     * BIZ-03: Void semua jurnal DEPUSE untuk log ini.
     *
     * Pattern LIKE 'DEPUSE-%-{log_id}' mencover kasus edit yang mengganti
     * asset_id — jurnal DEPUSE pakai asset_id LAMA, jadi voidExistingJournal
     * pakai document_number DEPUSE-{new_asset_id}-{log_id} akan miss.
     * LIKE pattern menangkap DEPUSE-{any_asset}-{log_id}.
     *
     * Note: log_id di suffix — pattern aman dari false-match antar log
     * (LIKE 'DEPUSE-%-5' tidak match 'DEPUSE-%-15').
     */
    private function voidDepreciationJournalsForLog(RentalLog $log): void
    {
        $journals = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $log->company_id)
            ->where('document_number', 'like', sprintf('DEPUSE-%%-%d', $log->id))
            ->where('status', 'posted')
            ->get();

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
