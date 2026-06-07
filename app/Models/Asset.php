<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'asset_code',
        'name',
        'type',
        'plate_number',
        'purchase_date',
        'purchase_price',
        'useful_life_months',
        'salvage_value',
        'account_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date'      => 'date',
            'purchase_price'     => 'decimal:2',
            'salvage_value'      => 'decimal:2',
            'useful_life_months' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getMonthlyDepreciationAttribute(): float
    {
        if (! $this->useful_life_months || $this->useful_life_months <= 0) {
            return 0;
        }

        return round(
            ((float) $this->purchase_price - (float) $this->salvage_value) / $this->useful_life_months,
            2,
        );
    }
}
