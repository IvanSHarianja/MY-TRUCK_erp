<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
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
}
