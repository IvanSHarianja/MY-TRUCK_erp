<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProjectAddendum;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

/**
 * Mengajukan pekerjaan tambah = izin operasional. Menyetujui = izin
 * "Setujui Perubahan Kontrak" (default Owner). Tidak bisa diedit/dihapus:
 * koreksi lewat tolak/batal, atau void invoice-nya.
 */
class ProjectAddendumPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, ProjectAddendum $a): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool
    {
        return $this->check($user, Permission::OperationalManage) || $this->check($user, Permission::ContractApprove);
    }
    public function update(User $user, ProjectAddendum $a): bool { return false; }
    public function delete(User $user, ProjectAddendum $a): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
}
