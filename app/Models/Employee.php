<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'employee_id',
        'name',
        'position',
        'assigned_asset_id',
        'join_date',
        'phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function assignedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'assigned_asset_id');
    }
}
