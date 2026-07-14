<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class ArmadaContract extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['contract_number', 'client_id', 'tarif_per_rit', 'billed_rit', 'status'])
            ->logOnlyDirty()
            ->useLogName('armada_contract');
    }

    protected $fillable = [
        'company_id',
        'contract_number',
        'client_id',
        'tipe_kontrak',
        'include_bbm',
        'include_operator',
        'route_description',
        'tarif_per_rit',
        'bbm_liter_per_rit',
        'harga_bbm_per_liter',
        'gaji_supir_per_hari',
        'uang_makan_per_hari',
        'uang_jalan_per_rit',
        'premi_per_rit',
        'billed_rit',
        'status',
        'started_at',
        'ended_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tarif_per_rit'       => 'decimal:2',
        'billed_rit'          => 'integer',
        'started_at'          => 'date',
        'ended_at'            => 'date',
        'include_bbm'         => 'boolean',
        'include_operator'    => 'boolean',
        'bbm_liter_per_rit'   => 'decimal:2',
        'harga_bbm_per_liter' => 'decimal:2',
        'gaji_supir_per_hari' => 'decimal:2',
        'uang_makan_per_hari' => 'decimal:2',
        'uang_jalan_per_rit'  => 'decimal:2',
        'premi_per_rit'       => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ritLogs(): HasMany
    {
        return $this->hasMany(RitLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Total rit dari semua RitLog */
    public function getTotalRitAttribute(): int
    {
        return (int) $this->ritLogs()->sum('rit_count');
    }

    /** Rit yang belum ditagih = total - billed */
    public function getUnbilledRitAttribute(): int
    {
        return max(0, $this->total_rit - (int) $this->billed_rit);
    }

    /** Nilai yang siap ditagih */
    public function getNilaiSiapTagihAttribute(): float
    {
        return $this->unbilled_rit * (float) $this->tarif_per_rit;
    }

    public function isAktif(): bool   { return $this->status === 'aktif'; }
    public function isSelesai(): bool { return $this->status === 'selesai'; }

    /**
     * Derive include_bbm/operator dari tipe_kontrak — sama pattern dengan
     * RentalContract. Kolom DB flag diabaikan (safety terhadap UI drift).
     */
    public function includesBbm(): bool
    {
        return $this->tipe_kontrak === 'all_in';
    }

    public function includesOperator(): bool
    {
        return in_array($this->tipe_kontrak, ['all_in', 'semi'], true);
    }

    protected static function booted(): void
    {
        // Block hapus jika sudah ada invoice atau billed_rit > 0
        static::deleting(function (ArmadaContract $contract) {
            if ((int) $contract->billed_rit > 0) {
                throw new \RuntimeException(
                    "Kontrak {$contract->contract_number} sudah ada rit yang ditagih. Void invoice terkait dulu sebelum hapus."
                );
            }
            // Cek apakah ada invoice yang link ke kontrak ini
            $hasInvoice = \App\Models\Invoice::withoutGlobalScopes()
                ->where('source_type', 'armada_contract')
                ->where('source_id', $contract->id)
                ->whereNotIn('status', ['void'])
                ->exists();

            if ($hasInvoice) {
                throw new \RuntimeException(
                    "Kontrak {$contract->contract_number} masih punya invoice aktif. Void invoice terkait dulu."
                );
            }
        });

        // Validasi transisi status → 'selesai' (sama pola dengan RentalContract).
        // Kontrak armada hanya boleh selesai kalau semua rit sudah ditagih.
        static::saving(function (ArmadaContract $contract) {
            if (! $contract->isDirty('status')) {
                return;
            }
            if ($contract->status !== 'selesai') {
                return;
            }

            $totalRit = (int) $contract->ritLogs()->sum('rit_count');
            $unbilledRit = max(0, $totalRit - (int) $contract->billed_rit);

            if ($unbilledRit > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => sprintf(
                        'Kontrak %s tidak bisa diselesaikan: masih ada %d rit yang belum ditagih (nilai Rp %s). Tagih dulu semua rit sebelum ubah status ke Selesai.',
                        $contract->contract_number,
                        $unbilledRit,
                        number_format($unbilledRit * (float) $contract->tarif_per_rit, 0, ',', '.'),
                    ),
                ]);
            }
        });
    }
}
