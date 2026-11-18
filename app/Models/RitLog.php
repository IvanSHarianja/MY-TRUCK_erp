<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBuktiTf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RitLog extends Model
{
    use BelongsToCompany;
    use HasBuktiTf;

    protected $fillable = [
        'company_id',
        'armada_contract_id',
        'asset_id',
        'driver_id',
        'log_date',
        'rit_count',
        'volume_m3',
        'tonase',
        'hari_kerja',
        'tarif',
        'solar_liter',
        'uang_jalan_supir',
        'uang_makan_supir',
        'premi_supir',
        'override_biaya',
        'journal_entry_id',
        'invoice_id',
        'notes',
        'created_by',
        'bukti_tf_path',
    ];

    protected $casts = [
        'log_date'         => 'date',
        'rit_count'        => 'integer',
        'volume_m3'        => 'decimal:2',
        'tonase'           => 'decimal:2',
        'hari_kerja'       => 'decimal:1',
        'tarif'            => 'integer',
        'solar_liter'      => 'decimal:2',
        'uang_jalan_supir' => 'decimal:2',
        'uang_makan_supir' => 'decimal:2',
        'premi_supir'      => 'decimal:2',
        'override_biaya'   => 'boolean',
    ];

    protected static function booted(): void
    {
        // Tarif dikunci saat log dibuat — perubahan tarif kontrak (lewat
        // persetujuan Owner) hanya berlaku untuk log berikutnya.
        static::creating(function (RitLog $log) {
            if ($log->tarif === null && $log->armada_contract_id) {
                $log->tarif = ArmadaContract::withoutGlobalScopes()->whereKey($log->armada_contract_id)->value('tarif_per_rit');
            }
        });
    }

    /** Tarif per satuan yang berlaku untuk log ini (cadangan: tarif kontrak). */
    public function effectiveTarif(?ArmadaContract $contract = null): float
    {
        return (float) ($this->tarif ?? ($contract ?? $this->armadaContract)?->tarif_per_rit ?? 0);
    }

    public function armadaContract(): BelongsTo
    {
        return $this->belongsTo(ArmadaContract::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBilled(): bool
    {
        return $this->invoice_id !== null;
    }
}
