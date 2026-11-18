<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RitLog;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

/**
 * Log harian armada memposting jurnal biaya — wajib izin CRUD Operasional.
 * Tanpa policy ini Filament mengizinkan SEMUA anggota PT (termasuk Viewer)
 * menambah log lewat tab di halaman kontrak.
 */
class RitLogPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, RitLog $log): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::OperationalManage); }
    public function update(User $user, RitLog $log): bool { return $this->check($user, Permission::OperationalManage); }
    public function delete(User $user, RitLog $log): bool { return $this->check($user, Permission::OperationalManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::OperationalManage); }
}
