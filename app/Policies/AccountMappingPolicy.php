<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AccountMapping;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class AccountMappingPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, AccountMapping $mapping): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
    public function update(User $user, AccountMapping $mapping): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
    public function delete(User $user, AccountMapping $mapping): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
}
