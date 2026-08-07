<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Company;
use App\Models\CompanyRolePermission;
use App\Models\User;

/**
 * Per-tenant permission resolver.
 *
 * Layer di antara RoleMatrix (default policy) dengan CompanyRolePermission
 * (per-PT override). Ownership check + fallback logic terpusat di sini.
 *
 * ATURAN:
 *   - Owner selalu punya SEMUA permission — TIDAK bisa dikutik owner sendiri
 *     via UI (safety, cegah lockout).
 *   - Admin / Accountant / Viewer default sesuai RoleMatrix; override via
 *     row di company_role_permissions.
 *   - Hanya user role Owner + Permission::RoleManage yang bisa panggil set().
 */
class RoleAccessManager
{
    /**
     * Per-request memoization supaya check berulang untuk role yang sama
     * tidak query DB berulang. Key: "company:role:permission".
     *
     * @var array<string, bool>
     */
    private array $cache = [];

    /**
     * Apakah role di tenant tersebut punya permission tertentu?
     */
    public function has(Company $company, Role $role, Permission $permission): bool
    {
        // Owner immutable — selalu semua permission.
        if ($role === Role::Owner) {
            return true;
        }

        $cacheKey = "{$company->id}:{$role->value}:{$permission->value}";
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $override = CompanyRolePermission::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role', $role->value)
            ->where('permission', $permission->value)
            ->first();

        $result = $override
            ? (bool) $override->is_granted
            : RoleMatrix::has($role, $permission);

        return $this->cache[$cacheKey] = $result;
    }

    /**
     * Update permission untuk role di tenant tersebut.
     * Throw kalau role = Owner (immutable). Simpan updated_by untuk audit.
     */
    public function set(
        Company $company,
        Role $role,
        Permission $permission,
        bool $isGranted,
        ?User $updatedBy = null,
    ): void {
        if ($role === Role::Owner) {
            throw new \RuntimeException(
                'Permission Owner immutable — Owner selalu punya akses penuh.'
            );
        }

        CompanyRolePermission::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $company->id,
                'role'       => $role->value,
                'permission' => $permission->value,
            ],
            [
                'is_granted' => $isGranted,
                'updated_by' => $updatedBy?->id,
            ],
        );

        // Invalidate memoization untuk key ini
        unset($this->cache["{$company->id}:{$role->value}:{$permission->value}"]);
    }

    /**
     * Reset semua override — kembalikan matrix PT ke default RoleMatrix.
     * Kalau $role diberi, hanya reset untuk role itu.
     */
    public function reset(Company $company, ?Role $role = null): int
    {
        $q = CompanyRolePermission::withoutGlobalScopes()
            ->where('company_id', $company->id);

        if ($role) {
            if ($role === Role::Owner) return 0;
            $q->where('role', $role->value);
        }

        // Clear cache untuk company ini
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, "{$company->id}:")) {
                unset($this->cache[$key]);
            }
        }

        return $q->delete();
    }

    /**
     * Snapshot matrix efektif untuk PT tsb.
     * Merge default (RoleMatrix) dengan override (DB).
     *
     * @return array<int, array{
     *   permission: Permission,
     *   label: string,
     *   group: string,
     *   allowed_by_role: array<string, bool>,
     * }>
     */
    public function matrixFor(Company $company): array
    {
        // Batch load semua override sekali — hindari N+1.
        $overrides = CompanyRolePermission::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get()
            ->keyBy(fn ($row) => $row->role->value . ':' . $row->permission->value);

        $rows = [];
        foreach (Permission::cases() as $perm) {
            $allowed = [];
            foreach (Role::cases() as $role) {
                if ($role === Role::Owner) {
                    $allowed[$role->value] = true;
                    continue;
                }
                $key      = $role->value . ':' . $perm->value;
                $override = $overrides->get($key);

                $allowed[$role->value] = $override
                    ? (bool) $override->is_granted
                    : RoleMatrix::has($role, $perm);
            }

            $rows[] = [
                'permission'      => $perm,
                'label'           => $perm->label(),
                'group'           => $perm->group(),
                'allowed_by_role' => $allowed,
            ];
        }

        return $rows;
    }

    /**
     * Matrix grouped per area — untuk render UI dengan section headers.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function matrixGroupedFor(Company $company): array
    {
        $bucket = [];
        foreach (Permission::groupOrder() as $group) {
            $bucket[$group] = [];
        }

        foreach ($this->matrixFor($company) as $row) {
            $bucket[$row['group']][] = $row;
        }

        return array_filter($bucket, fn ($rows) => ! empty($rows));
    }
}
