<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Debt;
use App\Models\User;
use App\Policies\Concerns\UsesRoleMatrix;

/**
 * Hutang & cicilan: catat/bayar = izin "Kelola Hutang & Cicilan". Data hutang
 * (pokok, tenor, bunga) tidak diedit setelah dibuat — jadwal & jurnal sudah
 * terbentuk; koreksi dengan hapus (bila belum ada cicilan dibayar) lalu catat ulang.
 */
class DebtPolicy
{
    use UsesRoleMatrix;

    public function viewAny(User $user): bool { return $this->checkTenantAccess($user); }
    public function view(User $user, Debt $d): bool { return $this->checkTenantAccess($user); }
    public function create(User $user): bool { return $this->check($user, Permission::DebtManage); }
    public function update(User $user, Debt $d): bool { return false; }
    public function delete(User $user, Debt $d): bool { return $this->check($user, Permission::DebtManage); }
    public function deleteAny(User $user): bool { return false; }
}
