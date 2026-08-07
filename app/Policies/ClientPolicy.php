<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class ClientPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->check($user, Permission::MasterDataManage);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->check($user, Permission::MasterDataManage);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->check($user, Permission::MasterDataManage);
    }

    public function deleteAny(User $user): bool
    {
        return $this->check($user, Permission::MasterDataManage);
    }
}
