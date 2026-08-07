<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class EmployeePolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, Employee $employee): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
    public function update(User $user, Employee $employee): bool { return $this->check($user, Permission::MasterDataManage); }
    public function delete(User $user, Employee $employee): bool { return $this->check($user, Permission::MasterDataManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
}
