<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RentalLog;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

/**
 * Log jam kerja rental memposting jurnal biaya — wajib izin CRUD Operasional.
 * Tanpa policy ini Filament mengizinkan SEMUA anggota PT (termasuk Viewer).
 */
class RentalLogPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, RentalLog $log): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::OperationalManage); }
    public function update(User $user, RentalLog $log): bool { return $this->check($user, Permission::OperationalManage); }
    public function delete(User $user, RentalLog $log): bool { return $this->check($user, Permission::OperationalManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::OperationalManage); }
}
