<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'entry_number',
        'entry_date',
        'document_number',
        'document_type',
        'business_unit_id',
        'description',
        'total_amount',
        'period_year',
        'period_month',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date'   => 'date',
            'posted_at'    => 'datetime',
            'total_amount' => 'decimal:2',
            'period_year'  => 'integer',
            'period_month' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order');
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function getTotalDebitAttribute(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function getTotalKreditAttribute(): float
    {
        return (float) $this->lines->sum('kredit');
    }

    public function isBalanced(): bool
    {
        return round($this->total_debit, 2) === round($this->total_kredit, 2);
    }
}
