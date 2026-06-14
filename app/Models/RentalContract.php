<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalContract extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'contract_number',
        'client_id',
        'asset_id',
        'tarif_per_jam',
        'lokasi_kerja',
        'billed_jam',
        'status',
        'started_at',
        'ended_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tarif_per_jam' => 'decimal:2',
        'billed_jam'    => 'decimal:2',
        'started_at'    => 'date',
        'ended_at'      => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function rentalLogs(): HasMany
    {
        return $this->hasMany(RentalLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Total jam kerja dari semua RentalLog */
    public function getTotalJamAttribute(): float
    {
        return round((float) $this->rentalLogs()->sum('jam_kerja'), 2);
    }

    /** Jam yang belum ditagih = total - billed */
    public function getUnbilledJamAttribute(): float
    {
        return round(max(0, $this->total_jam - (float) $this->billed_jam), 2);
    }

    /** Nilai siap tagih */
    public function getNilaiSiapTagihAttribute(): float
    {
        return round($this->unbilled_jam * (float) $this->tarif_per_jam, 2);
    }

    public function isAktif(): bool   { return $this->status === 'aktif'; }
    public function isSelesai(): bool { return $this->status === 'selesai'; }
}
