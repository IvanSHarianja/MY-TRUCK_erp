<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProjectUnitLog;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

/** Log pemakaian unit proyek memposting jurnal biaya — wajib izin CRUD Operasional. */
class ProjectUnitLogPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, ProjectUnitLog $log): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::OperationalManage); }
    public function update(User $user, ProjectUnitLog $log): bool { return $this->check($user, Permission::OperationalManage); }
    public function delete(User $user, ProjectUnitLog $log): bool { return $this->check($user, Permission::OperationalManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::OperationalManage); }
}
