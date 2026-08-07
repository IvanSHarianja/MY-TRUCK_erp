<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RentalContract;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class RentalContractPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, RentalContract $contract): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::OperationalManage); }
    public function update(User $user, RentalContract $contract): bool { return $this->check($user, Permission::OperationalManage); }
    public function delete(User $user, RentalContract $contract): bool { return $this->check($user, Permission::OperationalManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::OperationalManage); }
}
