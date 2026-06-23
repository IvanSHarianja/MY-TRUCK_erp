<?php

namespace App\Observers;

use App\Models\Asset;
use App\Models\RentalContract;

class RentalContractObserver
{
    /**
     * Saat kontrak rental baru dibuat dengan status aktif → asset status jadi 'aktif'
     * (akan menandakan asset sedang dipakai untuk rental)
     */
    public function created(RentalContract $contract): void
    {
        if ($contract->status === 'aktif') {
            $this->markAssetAsActive($contract->asset_id);
        }
    }

    /**
     * Saat status kontrak berubah:
     * - jadi 'aktif' → set asset aktif
     * - jadi 'selesai'/'batal' → cek apakah masih ada kontrak aktif lain untuk asset itu
     *   - kalau tidak ada → asset balik ke 'aktif' (status default untuk asset siap pakai)
     */
    public function updated(RentalContract $contract): void
    {
        if ($contract->wasChanged('status')) {
            if ($contract->status === 'aktif') {
                $this->markAssetAsActive($contract->asset_id);
            } else {
                $this->maybeReleaseAsset($contract->asset_id, $contract->id);
            }
        }
    }

    public function deleted(RentalContract $contract): void
    {
        $this->maybeReleaseAsset($contract->asset_id, $contract->id);
    }

    private function markAssetAsActive(int $assetId): void
    {
        Asset::withoutGlobalScopes()
            ->where('id', $assetId)
            ->update(['status' => 'aktif']);
    }

    private function maybeReleaseAsset(int $assetId, int $excludeContractId): void
    {
        $stillHasActive = RentalContract::withoutGlobalScopes()
            ->where('asset_id', $assetId)
            ->where('id', '!=', $excludeContractId)
            ->where('status', 'aktif')
            ->exists();

        if (! $stillHasActive) {
            Asset::withoutGlobalScopes()
                ->where('id', $assetId)
                ->update(['status' => 'aktif']);  // status default
        }
    }
}
