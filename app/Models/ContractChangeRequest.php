<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Pengajuan perubahan kontrak yang menunggu / sudah diputuskan Owner.
 * Logika ada di App\Services\ContractChangeService.
 */
class ContractChangeRequest extends Model
{
    use BelongsToCompany;

    public const PENDING   = 'pending';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'contract_type', 'contract_id', 'changes', 'reason', 'status',
        'requested_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'changes'     => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function contract(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING   => 'Menunggu',
            self::APPROVED  => 'Disetujui',
            self::REJECTED  => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            default         => $this->status,
        };
    }
}
