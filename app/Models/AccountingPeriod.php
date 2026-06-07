<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'period_year',
        'period_month',
        'status',
        'closed_by',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_year'  => 'integer',
            'period_month' => 'integer',
            'closed_at'    => 'datetime',
        ];
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
