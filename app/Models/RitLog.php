<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RitLog extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'armada_contract_id',
        'asset_id',
        'driver_id',
        'log_date',
        'rit_count',
        'solar_liter',
        'uang_jalan_supir',
        'uang_makan_supir',
        'premi_supir',
        'override_biaya',
        'journal_entry_id',
        'invoice_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'log_date'         => 'date',
        'rit_count'        => 'integer',
        'solar_liter'      => 'decimal:2',
        'uang_jalan_supir' => 'decimal:2',
        'uang_makan_supir' => 'decimal:2',
        'premi_supir'      => 'decimal:2',
        'override_biaya'   => 'boolean',
    ];

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
