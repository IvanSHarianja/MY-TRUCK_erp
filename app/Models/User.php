<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Company;
use App\Support\RoleAccessManager;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->companies()->wherePivot('is_active', true)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->companies()
            ->where('companies.id', $tenant->getKey())
            ->wherePivot('is_active', true)
            ->exists();
    }

    // ============================================================
    // Role & Permission helpers — bekerja sama dengan RoleMatrix
    // ============================================================

    /**
     * Role user di tenant ini, atau null kalau tidak-tenant/inactive.
     */
    public function roleIn(Model $tenant): ?Role
    {
        $pivot = $this->companies()
            ->where('companies.id', $tenant->getKey())
            ->first()
            ?->pivot;

        if (! $pivot || ! $pivot->is_active) {
            return null;
        }

        return Role::tryFrom($pivot->role);
    }

    /**
     * Apakah user punya permission tertentu di tenant tersebut?
     *
     * Delegate ke RoleAccessManager yang cek:
     *   1. Owner → selalu true (immutable)
     *   2. Role lain → cek override DB (CompanyRolePermission) dulu, fallback RoleMatrix default.
     *
     * Return false kalau: user bukan anggota tenant, pivot is_active=false,
     * atau role tidak punya permission itu (default + override sama-sama false).
     */
    public function canIn(Model $tenant, Permission $permission): bool
    {
        $role = $this->roleIn($tenant);
        if (! $role) {
            return false;
        }
        if (! $tenant instanceof Company) {
            return false;
        }
        return app(RoleAccessManager::class)->has($tenant, $role, $permission);
    }

    /**
     * Shortcut: cek permission di tenant Filament yang sedang aktif.
     * Dipakai di halaman non-Resource (canAccess) dan Filament actions.
     */
    public function canCurrent(Permission $permission): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Model) {
            return false;
        }
        return $this->canIn($tenant, $permission);
    }

    /**
     * Shortcut boolean untuk role-based check langsung
     * (mis. UI "is owner" tanpa lewat permission).
     */
    public function isRoleIn(Model $tenant, Role $role): bool
    {
        return $this->roleIn($tenant) === $role;
    }
}
