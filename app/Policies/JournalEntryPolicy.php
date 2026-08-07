<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

class JournalEntryPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $this->checkTenantAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->check($user, Permission::JournalPost);
    }

    public function update(User $user, JournalEntry $entry): bool
    {
        return $this->check($user, Permission::JournalPost);
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        // Delete = hanya untuk draft. Journal posted tidak boleh delete,
        // hanya void. Owner/Admin cukup (sejalan dengan void).
        return $this->check($user, Permission::JournalVoid);
    }

    public function deleteAny(User $user): bool
    {
        return $this->check($user, Permission::JournalVoid);
    }

    public function void(User $user, JournalEntry $entry): bool
    {
        return $this->check($user, Permission::JournalVoid);
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $this->check($user, Permission::JournalPost);
    }
}
