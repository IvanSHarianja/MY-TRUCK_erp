<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    /**
     * BUG-32: opt-in strict mode — kalau env STRICT_TENANT_SCOPE=true,
     * scope THROW saat tenant tidak set (di luar Filament panel).
     * Caller CLI/queue harus explicit pakai ->withoutGlobalScopes() atau
     * ->where('company_id', $id) untuk override.
     *
     * Default off supaya seeder / migrasi backfill / artisan command yang
     * belum sempat di-audit tidak break. Aktifkan di production untuk
     * catch tenant leak lebih dini.
     */
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $tenant = Filament::getTenant();

            if ($tenant instanceof Company) {
                $query->where($query->getModel()->getTable() . '.company_id', $tenant->getKey());
                return;
            }

            // Strict mode — tenant tidak set tapi query lewat model bertrait.
            // Kalau caller sengaja lintas tenant, harus pakai withoutGlobalScopes().
            if (config('tenancy.strict_scope', false) && ! app()->runningInConsole()) {
                throw new \RuntimeException(
                    'Tenant scope failsafe: model ' . $query->getModel()::class
                    . ' di-query tanpa Filament tenant. Gunakan withoutGlobalScopes() '
                    . 'atau set tenant via Filament::setTenant() dulu.'
                );
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
