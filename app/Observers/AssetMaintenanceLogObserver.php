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
 * `updated`: bila field biaya berubah, void jurnal lama + post baru. Baris
 * sparepart TIDAK disentuh di sini — nilainya ikut terbawa karena
 * postExpenseJournal() membaca ulang items dari DB. Perubahan daftar sparepart
 * ditangani MaintenanceService::updateLog() yang memakai withoutEvents, jadi
 * observer ini sengaja tidak ikut jalan di jalur tersebut.
 *
 * `deleting`: void jurnal + kembalikan stok sparepart. Urutannya penting —
 * baris sparepart harus dibaca sebelum baris log terhapus (FK cascade).
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

            if ($log->totalCost() <= 0) {
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
        DB::transaction(function () use ($log) {
            $this->maintenanceService->voidExistingJournal($log);
            $this->maintenanceService->restoreSpareparts($log);
        });
    }
}
