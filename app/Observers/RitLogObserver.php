<?php

namespace App\Observers;

use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RitLog;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\OperationalCostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-post jurnal beban operasional saat RitLog (ARMD) dibuat/diubah/dihapus.
 * Pola sama dengan RentalLogObserver, tapi:
 *   - Basis unit = rit_count (bukan jam_kerja).
 *   - Komponen tambahan: uang_jalan_supir.
 *   - Business Unit tag = ARMD.
 */
class RitLogObserver
{
    public function __construct(
        private OperationalCostService $costService,
        private JournalService $journalService,
        private DepreciationService $depreciationService,
    ) {}

    public function created(RitLog $log): void
    {
        if (! $log->journal_entry_id) {
            $this->postCostJournal($log);
        }

        // BIZ-03: DEPUSE penyusutan usage-based (aset method=per_rit)
        $this->postDepreciationJournal($log);
    }

    public function updated(RitLog $log): void
    {
        // MEDIUM-2: asset_id juga trigger repost supaya BBK re-tag ke asset baru.
        $costFields = [
            'rit_count', 'solar_liter', 'override_biaya',
            'uang_jalan_supir', 'uang_makan_supir', 'premi_supir',
            'asset_id',
        ];
        $costChanged = collect($costFields)->contains(fn ($f) => $log->wasChanged($f));

        // BIZ-03: DEPUSE bergantung pada rit_count, asset_id, log_date.
        $depFields = ['rit_count', 'asset_id', 'log_date'];
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
            $log->refresh();

            if ($costChanged) {
                $this->postCostJournal($log);
            }
            if ($depChanged) {
                $this->postDepreciationJournal($log);
            }
        });
    }

    public function deleting(RitLog $log): void
    {
        $this->voidExistingJournal($log);
        $this->voidDepreciationJournalsForLog($log);
    }

    private function postCostJournal(RitLog $log): void
    {
        $cost = $this->costService->computeRitLogCost($log);
        if ($cost['total'] <= 0) {
            return;
        }

        $company = Company::withoutGlobalScopes()->find($log->company_id);
        if (! $company) return;

        $armdBu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'ARMD')
            ->first();

        // Sprint 2.5: role-based lookup dengan fallback code
        $accBbm   = Account::findByRoleOrCode(\App\Enums\AccountRole::CogsBbm, '551100', $company->id);
        $accGaji  = Account::findByRoleOrCode(\App\Enums\AccountRole::OpexGaji, '552200', $company->id);
        $accPremi = Account::findByRoleOrCode(\App\Enums\AccountRole::CogsPremiUangJalan, '551200', $company->id);
        $accKas   = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);

        if (! $accKas) {
            Log::warning("RitLogObserver: akun Kas (111100) tidak ditemukan/postable untuk company {$company->id}. Skip auto-post.");
            return;
        }

        $logDate = Carbon::parse($log->log_date);

        try {
            $this->journalService->assertPeriodOpen($company, $logDate->year, $logDate->month);
        } catch (\Throwable $e) {
            Log::info("RitLogObserver: periode {$logDate->year}-{$logDate->month} closed. Skip auto-post log {$log->id}.");
            return;
        }

        $description = 'Biaya operasional Rit Log '
            . optional($log->armadaContract)->contract_number
            . ' — ' . $logDate->format('d/m/Y')
            . ' (' . $log->rit_count . ' rit)';

        $journal = $this->journalService->createEntryWithLines(
            company: $company,
            date: $logDate,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $logDate,
                'document_number'  => 'BBK-RT-' . $log->id,
                'document_type'    => 'bkk',
                'business_unit_id' => optional($armdBu)->id,
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
                // Tag semua line BEBAN dengan asset_id dari rit_log — untuk P&L per unit.
                $assetId = $log->asset_id;
                $prefix = $log->asset ? '[' . $log->asset->asset_code . '] ' : '';

                $lines = [];

                if ($cost['bbm'] > 0 && $accBbm) {
                    $lines[] = ['account_id' => $accBbm->id, 'asset_id' => $assetId, 'description' => $prefix . 'Beban BBM Solar', 'debit' => $cost['bbm'], 'kredit' => 0];
                }
                if ($cost['gaji'] > 0 && $accGaji) {
                    $lines[] = ['account_id' => $accGaji->id, 'asset_id' => $assetId, 'description' => $prefix . 'Gaji supir', 'debit' => $cost['gaji'], 'kredit' => 0];
                }
                if ($cost['makan'] > 0 && $accGaji) {
                    $lines[] = ['account_id' => $accGaji->id, 'asset_id' => $assetId, 'description' => $prefix . 'Tunjangan makan supir', 'debit' => $cost['makan'], 'kredit' => 0];
                }
                if ($cost['uang_jalan'] > 0 && $accPremi) {
                    $lines[] = ['account_id' => $accPremi->id, 'asset_id' => $assetId, 'description' => $prefix . 'Uang jalan supir', 'debit' => $cost['uang_jalan'], 'kredit' => 0];
                }
                if ($cost['premi'] > 0 && $accPremi) {
                    $lines[] = ['account_id' => $accPremi->id, 'asset_id' => $assetId, 'description' => $prefix . 'Premi supir', 'debit' => $cost['premi'], 'kredit' => 0];
                }

                $lines[] = ['account_id' => $accKas->id, 'description' => 'Pembayaran biaya operasional angkutan', 'debit' => 0, 'kredit' => $cost['total']];

                return $lines;
            },
        );

        RitLog::withoutEvents(function () use ($log, $journal) {
            $log->update(['journal_entry_id' => $journal->id]);
        });
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
        );
    }

    /**
     * BIZ-03: Void semua jurnal DEPUSE untuk log ini. Pattern LIKE menangkap
     * kasus edit yang mengganti asset_id.
     */
    private function voidDepreciationJournalsForLog(RitLog $log): void
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
                    'Auto-void: RitLog ' . $log->id . ' diubah/dihapus',
                );
            } catch (\Throwable $e) {
                Log::warning("RitLogObserver: gagal void DEPUSE {$journal->entry_number}: {$e->getMessage()}");
            }
        }
    }
}
