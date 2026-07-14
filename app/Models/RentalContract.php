<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class RentalContract extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['contract_number', 'client_id', 'asset_id', 'tarif_per_jam', 'billed_jam', 'status'])
            ->logOnlyDirty()
            ->useLogName('rental_contract');
    }

    protected $fillable = [
        'company_id',
        'contract_number',
        'client_id',
        'asset_id',
        'tipe_rental',
        'include_bbm',
        'include_operator',
        'tarif_per_jam',
        'bbm_liter_per_jam',
        'harga_bbm_per_liter',
        'gaji_operator_per_hari',
        'uang_makan_per_hari',
        'premi_per_jam',
        'lokasi_kerja',
        'billed_jam',
        'status',
        'started_at',
        'ended_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tarif_per_jam'          => 'decimal:2',
        'billed_jam'             => 'decimal:2',
        'started_at'             => 'date',
        'ended_at'               => 'date',
        'include_bbm'            => 'boolean',
        'include_operator'       => 'boolean',
        'bbm_liter_per_jam'      => 'decimal:2',
        'harga_bbm_per_liter'    => 'decimal:2',
        'gaji_operator_per_hari' => 'decimal:2',
        'uang_makan_per_hari'    => 'decimal:2',
        'premi_per_jam'          => 'decimal:2',
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

    /**
     * Derive include_bbm dari tipe_rental (single source of truth).
     *
     * Kolom DB `include_bbm` sengaja diabaikan karena UI Filament tidak
     * selalu persist state Hidden field secara reliable — pakai tipe_rental
     * eksplisit lebih safe.
     */
    public function includesBbm(): bool
    {
        return $this->tipe_rental === 'all_in';
    }

    public function includesOperator(): bool
    {
        return in_array($this->tipe_rental, ['all_in', 'semi'], true);
    }

    protected static function booted(): void
    {
        static::deleting(function (RentalContract $contract) {
            if ((float) $contract->billed_jam > 0) {
                throw new \RuntimeException(
                    "Kontrak {$contract->contract_number} sudah ada jam yang ditagih. Void invoice terkait dulu sebelum hapus."
                );
            }
            $hasInvoice = \App\Models\Invoice::withoutGlobalScopes()
                ->where('source_type', 'rental_contract')
                ->where('source_id', $contract->id)
                ->whereNotIn('status', ['void'])
                ->exists();

            if ($hasInvoice) {
                throw new \RuntimeException(
                    "Kontrak {$contract->contract_number} masih punya invoice aktif. Void invoice terkait dulu."
                );
            }
        });

        // Validasi transisi status → 'selesai'.
        // Kontrak hanya boleh selesai kalau semua jam kerja sudah ditagih
        // (unbilled_jam = 0). Kalau tidak, revenue recognition tidak konsisten:
        // ada jam kerja tanpa invoice → piutang tidak tercatat.
        // Validasi dijalankan di 'saving' supaya tangkap semua path: Filament
        // form, action button, service, tinker, raw Eloquent update.
        static::saving(function (RentalContract $contract) {
            if (! $contract->isDirty('status')) {
                return;
            }
            if ($contract->status !== 'selesai') {
                return;
            }

            $totalJam = (float) $contract->rentalLogs()->sum('jam_kerja');
            $unbilledJam = round(max(0, $totalJam - (float) $contract->billed_jam), 2);

            if ($unbilledJam > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => sprintf(
                        'Kontrak %s tidak bisa diselesaikan: masih ada %s jam yang belum ditagih (nilai Rp %s). Tagih dulu semua jam kerja sebelum ubah status ke Selesai.',
                        $contract->contract_number,
                        number_format($unbilledJam, 2, ',', '.'),
                        number_format($unbilledJam * (float) $contract->tarif_per_jam, 0, ',', '.'),
                    ),
                ]);
            }
        });
    }
}
