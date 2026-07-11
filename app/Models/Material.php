<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'harga_per_satuan',
        'harga_pokok',
        'satuan',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'harga_per_satuan' => 'decimal:2',
        'harga_pokok'      => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    public function getDisplayNameAttribute(): string
    {
        return "[{$this->code}] {$this->name}";
    }
}
