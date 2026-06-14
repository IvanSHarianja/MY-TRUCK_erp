<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'npwp',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Total piutang berjalan (status terbit + sebagian) */
    public function getPiutangBerjalanAttribute(): float
    {
        return (float) $this->invoices()
            ->whereIn('status', ['terbit', 'sebagian'])
            ->selectRaw('SUM(amount - paid_amount) as total')
            ->value('total') ?? 0.0;
    }
}
