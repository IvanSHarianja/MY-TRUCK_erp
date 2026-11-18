<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ArmadaMobilization;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

/** Ongkos gendong ikut ditagih — wajib izin CRUD Operasional. */
class ArmadaMobilizationPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, ArmadaMobilization $m): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::OperationalManage); }
    public function update(User $user, ArmadaMobilization $m): bool { return $this->check($user, Permission::OperationalManage); }
    public function delete(User $user, ArmadaMobilization $m): bool { return $this->check($user, Permission::OperationalManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::OperationalManage); }
}
