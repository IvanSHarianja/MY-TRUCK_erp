<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model implements HasCurrentTenantLabel
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'owner_name',
        'fiscal_year',
        'fiscal_start',
        'fiscal_end',
        'address',
        'phone',
        'email',
        'logo',
        'is_active',
        'harga_solar_default',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_start'        => 'date',
            'fiscal_end'          => 'date',
            'is_active'           => 'boolean',
            // Integer cast — cegah bug rupiah 100× lipat pada form ->rupiah().
            'harga_solar_default' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function getCurrentTenantLabel(): string
    {
        return $this->name;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
