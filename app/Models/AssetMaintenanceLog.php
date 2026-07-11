<?php

namespace App\Models;

use App\Enums\MaintenanceType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Log satu record maintenance/service per aset.
 *
 * Alur (nanti di MaintenanceService Tahap 4):
 *   1. User input service via UI (aset, tanggal, tipe, biaya, HM, next service).
 *   2. Service auto-post jurnal Dr 551400 Beban Maintenance / Cr Kas atau Utang,
 *      tag ke business_unit_id sesuai Asset::defaultBusinessUnitCode().
 *   3. Widget dashboard tampilkan aset yang overdue service (hm sekarang >
 *      next_service_hm, atau today > next_service_date).
 *
 * Immutable-friendly: log yang sudah tersimpan dianggap historis; edit hanya
 * untuk notes/photo. Kalau ada kesalahan cost, buat log koreksi baru dengan
 * cost negatif (akan direfleksikan di jurnal balik).
 */
class AssetMaintenanceLog extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'maintenance_date', 'type', 'cost', 'vendor_id', 'next_service_hm', 'next_service_date'])
            ->logOnlyDirty()
            ->useLogName('asset_maintenance');
    }

    protected $fillable = [
        'company_id',
        'asset_id',
        'maintenance_date',
        'type',
        'description',
        'vendor_id',
        'cost',
        'hm_saat_service',
        'next_service_hm',
        'next_service_date',
        'journal_entry_id',
        'photo_url',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'maintenance_date'  => 'date',
        'type'              => MaintenanceType::class,
        'cost'              => 'decimal:2',
        'hm_saat_service'   => 'decimal:2',
        'next_service_hm'   => 'decimal:2',
        'next_service_date' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Apakah service ini sudah lewat interval preventive berikutnya —
     * dipakai widget alert "overdue maintenance".
     * Return null bila tidak ada target berikutnya.
     */
    public function isOverdue(?float $currentHm = null): ?bool
    {
        $byDate = $this->next_service_date && $this->next_service_date->isPast();
        $byHm   = $this->next_service_hm && $currentHm !== null && $currentHm > (float) $this->next_service_hm;

        if ($this->next_service_date === null && $this->next_service_hm === null) {
            return null;
        }

        return $byDate || $byHm;
    }
}
