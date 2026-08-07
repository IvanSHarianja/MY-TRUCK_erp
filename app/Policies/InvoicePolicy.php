<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class InvoicePolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->check($user, Permission::InvoiceCreate);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->check($user, Permission::InvoiceCreate);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        // Invoice::deleting model guard hanya izinkan draft/void — jadi delete
        // effektif hanya untuk draft cleanup. Owner/Admin cukup.
        return $this->check($user, Permission::InvoiceVoid);
    }

    public function deleteAny(User $user): bool
    {
        return $this->check($user, Permission::InvoiceVoid);
    }

    /**
     * Custom action: Void invoice (destructive, butuh audit trail).
     */
    public function void(User $user, Invoice $invoice): bool
    {
        return $this->check($user, Permission::InvoiceVoid);
    }
}
