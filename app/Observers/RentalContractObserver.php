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
     * Saat status kontrak berubah jadi 'aktif' → asset ke 'aktif' juga.
     * BUG-31: dulu ada `maybeReleaseAsset` yang set asset ke 'aktif'
     * (semantik "siap pakai"). No-op karena target sama dengan default.
     * Asset enum tidak punya 'idle' — kalau butuh, tambah via migrasi baru.
     * Sekarang: observer hanya handle transisi ke aktif; release tidak
     * ubah status asset (biar tidak mendorong asset yang lagi 'maintenance'
     * balik ke 'aktif' secara tidak sengaja).
     */
    public function updated(RentalContract $contract): void
    {
        if ($contract->wasChanged('status') && $contract->status === 'aktif') {
            $this->markAssetAsActive($contract->asset_id);
        }
    }

    private function markAssetAsActive(int $assetId): void
    {
        Asset::withoutGlobalScopes()
            ->where('id', $assetId)
            ->update(['status' => 'aktif']);
    }
}
