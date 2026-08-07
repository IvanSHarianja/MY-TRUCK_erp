<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class PaymentPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->check($user, Permission::PaymentManage);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->check($user, Permission::PaymentManage);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->check($user, Permission::PaymentManage);
    }

    public function deleteAny(User $user): bool
    {
        return $this->check($user, Permission::PaymentManage);
    }

    /**
     * Reverse payment = balance-safe, tapi tetap perlu audit-level control.
     * Owner/Admin/Accountant boleh (semua yang boleh input payment).
     */
    public function reverse(User $user, Payment $payment): bool
    {
        return $this->check($user, Permission::PaymentManage);
    }
}
