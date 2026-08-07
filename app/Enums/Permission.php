<?php

namespace App\Enums;

/**
 * Semua aksi granular yang bisa dikontrol per role.
 *
 * Dipakai bersama App\Support\RoleMatrix untuk menentukan role mana yang
 * punya akses ke aksi tertentu. Dipakai di Policy (auto-integrate Filament)
 * dan di canAccess() halaman non-Resource.
 *
 * Menambah permission baru:
 *   1. Tambah case di sini
 *   2. Tambah label + group
 *   3. Update matrix di App\Support\RoleMatrix
 *   4. Panggil dari Policy / canAccess() terkait
 */
enum Permission: string
{
    // === System (governance PT) ===
    case UserManage        = 'user.manage';
    case RoleManage        = 'role.manage';   // Kelola matriks izin per role (owner-only)
    case CompanyDelete     = 'company.delete';
    case PeriodClose       = 'period.close';
    case ActivityLogView   = 'activity_log.view';

    // === Master Data ===
    case ChartOfAccountsManage = 'coa.manage';
    case MasterDataManage      = 'master_data.manage';   // Client/Vendor/Employee/Material/Asset/BU/AccountMapping

    // === Operasional (kontrak & log) ===
    case OperationalManage = 'operational.manage';       // Rental/Armada/MaterialSale/Project/Log

    // === Financial (uang & jurnal) ===
    case InvoiceCreate     = 'invoice.create';
    case InvoiceVoid       = 'invoice.void';
    case PaymentManage     = 'payment.manage';
    case JournalPost       = 'journal.post';
    case JournalVoid       = 'journal.void';
    case QuickTransaction  = 'quick_tx.execute';
    case DepreciationRun   = 'depreciation.run';

    // === Laporan ===
    case ReportsView       = 'reports.view';
    case ReportsExportPdf  = 'reports.export_pdf';

    public function label(): string
    {
        return match ($this) {
            self::UserManage             => 'Manage User (invite / cabut akses)',
            self::RoleManage             => 'Kelola Matriks Akses Role',
            self::CompanyDelete          => 'Hapus PT',
            self::PeriodClose            => 'Close / Open Period Akuntansi',
            self::ActivityLogView        => 'Riwayat Aktivitas',
            self::ChartOfAccountsManage  => 'CRUD Chart of Accounts',
            self::MasterDataManage       => 'CRUD Master Data (Client/Vendor/Employee/Material/Asset/BU)',
            self::OperationalManage      => 'CRUD Operasional (Rental/Armada/Sale/Project)',
            self::InvoiceCreate          => 'Buat & Issue Invoice',
            self::InvoiceVoid            => 'Void Invoice',
            self::PaymentManage          => 'Input & Reverse Payment',
            self::JournalPost            => 'Post Journal Manual',
            self::JournalVoid            => 'Void Journal',
            self::QuickTransaction       => 'Quick Transaction',
            self::DepreciationRun        => 'Trigger Depresiasi Manual',
            self::ReportsView            => 'Lihat Laporan Keuangan',
            self::ReportsExportPdf       => 'Export PDF Laporan',
        };
    }

    /**
     * Grouping untuk display di halaman Matriks Akses.
     */
    public function group(): string
    {
        return match ($this) {
            self::UserManage,
            self::RoleManage,
            self::CompanyDelete,
            self::PeriodClose,
            self::ActivityLogView       => 'System',

            self::ChartOfAccountsManage,
            self::MasterDataManage      => 'Master Data',

            self::OperationalManage     => 'Operasional',

            self::InvoiceCreate,
            self::InvoiceVoid,
            self::PaymentManage,
            self::JournalPost,
            self::JournalVoid,
            self::QuickTransaction,
            self::DepreciationRun       => 'Financial',

            self::ReportsView,
            self::ReportsExportPdf      => 'Laporan',
        };
    }

    /**
     * Urutan group untuk sort di UI (System dulu, Financial di tengah, Laporan terakhir).
     */
    public static function groupOrder(): array
    {
        return ['System', 'Master Data', 'Operasional', 'Financial', 'Laporan'];
    }
}
