<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'parent_code',
        'name',
        'category',
        'sub_category',
        'normal_balance',
        'cash_flow_category',
        'tax_type',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getDisplayNameAttribute(): string
    {
        return "[{$this->code}] {$this->name}";
    }
}
