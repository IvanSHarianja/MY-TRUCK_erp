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

    // Integer cast — cegah bug rupiah 100× lipat pada form ->rupiah().
    protected $casts = [
        'harga_per_satuan' => 'integer',
        'harga_pokok'      => 'integer',
        'is_active'        => 'boolean',
    ];

    public function getDisplayNameAttribute(): string
    {
        return "[{$this->code}] {$this->name}";
    }
}
