<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Role;

/**
 * Single source of truth: mapping Role → Permission yang diizinkan.
 *
 * ==========================================================================
 * UBAH IZIN AKSES: EDIT ARRAY DI SINI, JANGAN DI POLICY/PAGE.
 * ==========================================================================
 *
 * Prinsip yang dipakai:
 *   - Owner  : akses penuh + governance (close period, hapus PT)
 *   - Admin  : CRUD data + void tindakan destructive (kecuali governance)
 *   - Accountant: input jurnal & operasional, TIDAK boleh void / manage master
 *   - Viewer : read-only + export PDF
 *
 * Filosofi "boleh void" ditaruh Owner+Admin, bukan Accountant, karena void
 * adalah audit event yang butuh persetujuan level lebih tinggi dari
 * operator harian. Kalau perusahaan Anda beda kebijakan, tinggal geser
 * baris Permission::InvoiceVoid / JournalVoid ke Role::Accountant.
 */
final class RoleMatrix
{
    /**
     * @return array<int, Permission>
     */
    public static function permissionsFor(Role $role): array
    {
        return match ($role) {
            Role::Owner => [
                // Owner = semua permission
                Permission::UserManage,
                Permission::RoleManage,
                Permission::CompanyDelete,
                Permission::PeriodClose,
                Permission::ActivityLogView,
                Permission::ChartOfAccountsManage,
                Permission::MasterDataManage,
                Permission::OperationalManage,
                Permission::InvoiceCreate,
                Permission::InvoiceVoid,
                Permission::PaymentManage,
                Permission::JournalPost,
                Permission::JournalVoid,
                Permission::QuickTransaction,
                Permission::DepreciationRun,
                Permission::ReportsView,
                Permission::ReportsExportPdf,
            ],

            Role::Admin => [
                Permission::UserManage,               // manage user (invite/remove) — TAPI cannot delete owner (business rule di page)
                Permission::ActivityLogView,
                Permission::ChartOfAccountsManage,
                Permission::MasterDataManage,
                Permission::OperationalManage,
                Permission::InvoiceCreate,
                Permission::InvoiceVoid,
                Permission::PaymentManage,
                Permission::JournalPost,
                Permission::JournalVoid,
                Permission::QuickTransaction,
                Permission::DepreciationRun,
                Permission::ReportsView,
                Permission::ReportsExportPdf,
                // BUKAN: RoleManage, CompanyDelete, PeriodClose (owner-only governance)
            ],

            Role::Accountant => [
                Permission::OperationalManage,
                Permission::InvoiceCreate,
                Permission::PaymentManage,
                Permission::JournalPost,
                Permission::QuickTransaction,
                Permission::DepreciationRun,
                Permission::ReportsView,
                Permission::ReportsExportPdf,
                // BUKAN: void*, master data, user manage, period, activity log
            ],

            Role::Viewer => [
                Permission::ReportsView,
                Permission::ReportsExportPdf,
                // Semua CRUD/void/manage: DILARANG
            ],
        };
    }

    /**
     * Apakah role tertentu punya permission tertentu?
     */
    public static function has(Role $role, Permission $permission): bool
    {
        return in_array($permission, self::permissionsFor($role), strict: true);
    }

    /**
     * Snapshot matrix untuk display UI.
     *
     * @return array<string, array{
     *   permission: Permission,
     *   label: string,
     *   group: string,
     *   allowed_by_role: array<string, bool>,
     * }>
     */
    public static function snapshot(): array
    {
        $rows = [];
        foreach (Permission::cases() as $perm) {
            $allowed = [];
            foreach (Role::cases() as $role) {
                $allowed[$role->value] = self::has($role, $perm);
            }

            $rows[] = [
                'permission'      => $perm,
                'label'           => $perm->label(),
                'group'           => $perm->group(),
                'allowed_by_role' => $allowed,
            ];
        }

        return $rows;
    }

    /**
     * Snapshot di-group per area — untuk render UI matrix dengan section headers.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function snapshotGrouped(): array
    {
        $bucket = [];
        foreach (Permission::groupOrder() as $group) {
            $bucket[$group] = [];
        }

        foreach (self::snapshot() as $row) {
            $bucket[$row['group']][] = $row;
        }

        return array_filter($bucket, fn ($rows) => ! empty($rows));
    }
}
