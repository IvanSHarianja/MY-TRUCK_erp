<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ArmadaContract;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class ArmadaContractPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, ArmadaContract $contract): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::OperationalManage); }
    public function update(User $user, ArmadaContract $contract): bool { return $this->check($user, Permission::OperationalManage); }
    public function delete(User $user, ArmadaContract $contract): bool { return $this->check($user, Permission::OperationalManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::OperationalManage); }
}
