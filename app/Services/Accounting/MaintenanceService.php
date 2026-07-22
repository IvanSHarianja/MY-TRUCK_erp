<?php

namespace App\Services\Accounting;

use App\Enums\MaintenanceType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service pencatatan pemeliharaan aset + auto-post jurnal beban maintenance.
 *
 * Alur log():
 *   1. Buat AssetMaintenanceLog record (data historis).
 *   2. Auto-post jurnal Dr 551400 Beban Maintenance / Cr 111100 Kas
 *      (asumsi bayar tunai — user bisa manual edit jurnal untuk ubah ke utang
 *      bila perlu). Business unit tag = Asset::defaultBusinessUnitCode().
 *   3. Simpan journal_entry_id balik ke log untuk cascade void bila dihapus.
 *
 * Kalau vendor_id ter-set, nama vendor masuk ke description jurnal supaya
 * mudah ditelusuri di laporan.
 *
 * Skip conditions:
 *   - cost <= 0 (log tercatat, tapi tidak buat jurnal — biasanya untuk
 *     inspeksi gratis atau service dalam garansi).
 *   - periode akuntansi closed (log warning, skip auto-post).
 */
class MaintenanceService
{
    public function __construct(private JournalService $journalService) {}

    /**
     * Catat 1 record maintenance + auto-post jurnal.
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
     * } $data
     */
    public function log(array $data): AssetMaintenanceLog
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($data['asset_id']);
        $company = Company::withoutGlobalScopes()->findOrFail($asset->company_id);

        return DB::transaction(function () use ($data, $asset, $company) {
            $maintenanceDate = Carbon::parse($data['maintenance_date']);

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

            if ((float) $log->cost > 0) {
                $this->postExpenseJournal($log, $asset, $company);
                $log->refresh();
            }

            return $log;
        });
    }

    /**
     * Post jurnal Dr Beban Maintenance / Cr Kas.
     * Dipanggil dari log() dan bisa direplay via observer (edit → repost).
     */
    public function postExpenseJournal(
        AssetMaintenanceLog $log,
        Asset $asset,
        Company $company,
    ): void {
        // BU tag dari asset default
        $buCode = $asset->defaultBusinessUnitCode();
        $bu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $buCode)
            ->first();

        // Akun beban (551400) & kas (111100)
        // Sprint 2.5: role-based lookup dengan fallback code
        $accBeban = Account::findByRoleOrCode(\App\Enums\AccountRole::CogsMaintenance, '551400', $company->id);
        $accKas   = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);

        if (! $accBeban || ! $accKas) {
            Log::warning("MaintenanceService: akun 551400 atau 111100 tidak ditemukan/postable untuk company {$company->id}. Skip auto-post log {$log->id}.");
            return;
        }

        $date = Carbon::parse($log->maintenance_date);

        try {
            $this->journalService->assertPeriodOpen($company, $date->year, $date->month);
        } catch (\Throwable $e) {
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
                'total_amount'     => $log->cost,
            ],
            linesFactory: fn (JournalEntry $entry) => [
                [
                    'account_id'  => $accBeban->id,
                    'asset_id'    => $asset->id,     // tag untuk P&L per unit
                    'description' => '[' . $asset->asset_code . '] ' . $log->description,
                    'debit'       => $log->cost,
                    'kredit'      => 0,
                ],
                [
                    'account_id'  => $accKas->id,
                    // asset_id di sisi kas: null (kas tidak related aset spesifik)
                    'description' => 'Pembayaran maintenance ' . optional($asset)->asset_code,
                    'debit'       => 0,
                    'kredit'      => $log->cost,
                ],
            ],
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
}
