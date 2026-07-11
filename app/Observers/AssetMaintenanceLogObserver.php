<?php

namespace App\Observers;

use App\Models\AssetMaintenanceLog;
use App\Models\Company;
use App\Services\Accounting\MaintenanceService;
use Illuminate\Support\Facades\DB;

/**
 * Observer AssetMaintenanceLog — hanya handle update & delete.
 *
 * `created` TIDAK di-handle observer karena MaintenanceService::log() sudah
 * post jurnal secara eksplisit di dalam transaction — pola ini menghindari
 * double-post kalau user create via Filament (yang panggil service langsung).
 *
 * `updated`: bila cost berubah, void jurnal lama + post baru.
 * `deleting`: void jurnal terkait.
 */
class AssetMaintenanceLogObserver
{
    public function __construct(private MaintenanceService $maintenanceService) {}

    public function updated(AssetMaintenanceLog $log): void
    {
        // Field yang mempengaruhi jurnal — kalau tidak berubah, skip.
        $costFields = ['cost', 'description', 'maintenance_date', 'vendor_id'];
        $changed = collect($costFields)->contains(fn ($f) => $log->wasChanged($f));
        if (! $changed) {
            return;
        }

        DB::transaction(function () use ($log) {
            $this->maintenanceService->voidExistingJournal($log);
            $log->refresh();

            if ((float) $log->cost <= 0) {
                return;
            }

            $company = Company::withoutGlobalScopes()->find($log->company_id);
            $asset = $log->asset;
            if ($company && $asset) {
                $this->maintenanceService->postExpenseJournal($log, $asset, $company);
            }
        });
    }

    public function deleting(AssetMaintenanceLog $log): void
    {
        $this->maintenanceService->voidExistingJournal($log);
    }
}
