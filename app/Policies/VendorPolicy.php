<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Concerns\UsesRoleMatrix;

class VendorPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, Vendor $vendor): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
    public function update(User $user, Vendor $vendor): bool { return $this->check($user, Permission::MasterDataManage); }
    public function delete(User $user, Vendor $vendor): bool { return $this->check($user, Permission::MasterDataManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::MasterDataManage); }
}
