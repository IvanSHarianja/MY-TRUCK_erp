<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class BusinessUnitPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, BusinessUnit $bu): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
    public function update(User $user, BusinessUnit $bu): bool { return $this->check($user, Permission::MasterDataManage); }
    public function delete(User $user, BusinessUnit $bu): bool { return $this->check($user, Permission::MasterDataManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
}
