<?php

namespace App\Filament\Pages\Reports\Concerns;

use App\Enums\Permission;

/**
 * Gate halaman laporan dengan permission Reporting dari Role Akses.
 *
 * - ReportsView      → akses halaman (menu hidden + 403 kalau URL diakses langsung)
 * - ReportsExportPdf → tombol "Export PDF" tampil
 *
 * Route PDF-nya sendiri dijaga di EnsurePdfTenantAccess — tombol yang disembunyikan
 * bukan kontrol akses, URL-nya tetap bisa diketik.
 */
trait RequiresReportPermission
{
    public static function canAccess(): bool
    {
        return auth()->user()?->canCurrent(Permission::ReportsView) ?? false;
    }

    protected static function canExportPdf(): bool
    {
        return auth()->user()?->canCurrent(Permission::ReportsExportPdf) ?? false;
    }
}
