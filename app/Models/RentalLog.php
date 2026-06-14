<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalLog extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'rental_contract_id',
        'asset_id',
        'operator_id',
        'log_date',
        'hm_awal',
        'hm_akhir',
        'jam_kerja',
        'solar_liter',
        'voucher_solar',
        'invoice_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'log_date'    => 'date',
        'hm_awal'     => 'decimal:2',
        'hm_akhir'    => 'decimal:2',
        'jam_kerja'   => 'decimal:2',
        'solar_liter' => 'decimal:2',
    ];

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'operator_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
