<?php

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\User;

/**
 * Shortcut untuk Policy classes yang delegasi cek ke RoleMatrix via User.
 *
 * Semua Policy di MY-TRUCK memakai pattern:
 *   - viewAny / view    : semua user tenant boleh (kontrol per Resource kalau butuh)
 *   - create / update   : permission tertentu (write)
 *   - delete            : permission tertentu (biasanya sama dengan write)
 *   - custom (void, dll): permission destructive
 *
 * Method canAny(user, permission) return true kalau user PT-aktif dan
 * roles-nya punya permission tsb via RoleMatrix.
 */
trait UsesRoleMatrix
{
    /**
     * Cek permission user di tenant Filament yang sedang aktif.
     * Return false untuk user tidak login / bukan anggota tenant / role tidak izin.
     */
    protected function check(?User $user, Permission $permission): bool
    {
        return $user?->canCurrent($permission) ?? false;
    }

    /**
     * User yang punya akses ke tenant (aktif) boleh selalu view (list & detail),
     * kecuali Resource override viewAny/view untuk konten sensitif.
     */
    protected function checkTenantAccess(?User $user): bool
    {
        if (! $user) return false;

        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $user->canAccessTenant($tenant);
    }
}
