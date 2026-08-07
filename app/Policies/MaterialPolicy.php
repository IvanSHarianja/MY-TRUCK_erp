<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Material;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class MaterialPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, Material $material): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
    public function update(User $user, Material $material): bool { return $this->check($user, Permission::MasterDataManage); }
    public function delete(User $user, Material $material): bool { return $this->check($user, Permission::MasterDataManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
}
