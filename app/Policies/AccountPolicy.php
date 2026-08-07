<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

/**
 * Chart of Accounts — permission khusus (bukan MasterDataManage) karena
 * struktur COA lebih sensitif dari master lain (mengubah COA = mengubah
 * fondasi laporan keuangan).
 */
class AccountPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, Account $account): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
    public function update(User $user, Account $account): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
    public function delete(User $user, Account $account): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
    public function deleteAny(User $user): bool { return $this->check($user, Permission::ChartOfAccountsManage); }
}
