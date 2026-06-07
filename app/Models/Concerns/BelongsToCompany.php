<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $tenant = Filament::getTenant();

            if ($tenant instanceof Company) {
                $query->where($query->getModel()->getTable() . '.company_id', $tenant->getKey());
            }
        });

        static::creating(function ($model) {
            if (! $model->company_id) {
                $tenant = Filament::getTenant();

                if ($tenant instanceof Company) {
                    $model->company_id = $tenant->getKey();
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
