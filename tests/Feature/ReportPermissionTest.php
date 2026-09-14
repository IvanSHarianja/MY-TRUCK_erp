<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Pages\Reports;
use App\Models\Company;
use App\Models\User;
use App\Support\RoleAccessManager;
use Tests\TestCase;

/**
 * Regression: permission ReportsView & ReportsExportPdf bisa di-toggle di
 * halaman Role Akses tapi tidak pernah dicek — halaman laporan dan PDF tetap
 * terbuka untuk semua anggota tenant.
 */
class ReportPermissionTest extends TestCase
{
    private const REPORT_PAGES = [
        Reports\AssetCostPerUnit::class,
        Reports\AssetDepreciation::class,
        Reports\BalanceSheet::class,
        Reports\CashFlow::class,
        Reports\CashFlowLak::class,
        Reports\EquityStatement::class,
        Reports\IncomeStatement::class,
        Reports\IncomeStatementByAsset::class,
        Reports\IncomeStatementMatrix::class,
        Reports\IncomeStatementUnified::class,
        Reports\TrialBalance::class,
    ];

    private const REPORT_PDF_ROUTES = [
        'pdf.trial-balance',
        'pdf.income-statement',
        'pdf.income-statement-matrix',
        'pdf.income-statement-by-asset',
        'pdf.balance-sheet',
        'pdf.equity-statement',
        'pdf.cash-flow',
        'pdf.cash-flow-lak',
        'pdf.asset-depreciation',
        'pdf.asset-cost-per-unit',
    ];

    /** @return array{0: Company, 1: User, 2: User} [company, owner, viewer] */
    private function tenantWithViewer(): array
    {
        $company = $this->createTenant();
        $owner   = $this->createTenantUser($company);
        $viewer  = $this->createTenantUser($company, [], Role::Viewer->value);

        return [$company, $owner, $viewer];
    }

    private function revoke(Company $company, User $owner, Permission $permission): void
    {
        app(RoleAccessManager::class)->set($company, Role::Viewer, $permission, false, $owner);
    }

    public function test_viewer_default_bisa_buka_laporan_dan_export_pdf(): void
    {
        [$company, , $viewer] = $this->tenantWithViewer();
        $this->actingAsTenant($viewer, $company);

        foreach (self::REPORT_PAGES as $page) {
            $this->assertTrue($page::canAccess(), "{$page} harus bisa diakses Viewer (default matrix)");
        }

        $this->get(route('pdf.trial-balance', ['tenant' => $company->slug]))->assertOk();
    }

    public function test_cabut_reports_view_menutup_semua_halaman_laporan(): void
    {
        [$company, $owner, $viewer] = $this->tenantWithViewer();
        $this->revoke($company, $owner, Permission::ReportsView);
        $this->actingAsTenant($viewer, $company);

        foreach (self::REPORT_PAGES as $page) {
            $this->assertFalse($page::canAccess(), "{$page} harus tertutup setelah ReportsView dicabut");
        }
    }

    public function test_cabut_reports_view_halaman_via_url_403(): void
    {
        [$company, $owner, $viewer] = $this->tenantWithViewer();
        $this->revoke($company, $owner, Permission::ReportsView);

        $this->actingAs($viewer)
            ->get("/admin/{$company->slug}/trial-balance")
            ->assertForbidden();
    }

    public function test_cabut_reports_export_pdf_menutup_semua_route_pdf_laporan(): void
    {
        [$company, $owner, $viewer] = $this->tenantWithViewer();
        $this->revoke($company, $owner, Permission::ReportsExportPdf);
        $this->actingAsTenant($viewer, $company);

        // Halaman tetap boleh dilihat — hanya export yang dicabut.
        $this->assertTrue(Reports\TrialBalance::canAccess());

        foreach (self::REPORT_PDF_ROUTES as $route) {
            $this->get(route($route, ['tenant' => $company->slug]))
                ->assertStatus(403, "Route {$route} harus 403 setelah ReportsExportPdf dicabut");
        }
    }

    public function test_cabut_reports_view_juga_menutup_pdf_walau_export_masih_diizinkan(): void
    {
        [$company, $owner, $viewer] = $this->tenantWithViewer();
        $this->revoke($company, $owner, Permission::ReportsView);
        $this->actingAs($viewer);

        $this->get(route('pdf.balance-sheet', ['tenant' => $company->slug]))->assertForbidden();
    }

    public function test_owner_selalu_bisa_export_pdf(): void
    {
        [$company, $owner] = $this->tenantWithViewer();
        $this->revoke($company, $owner, Permission::ReportsView);
        $this->revoke($company, $owner, Permission::ReportsExportPdf);
        $this->actingAsTenant($owner, $company);

        $this->assertTrue(Reports\TrialBalance::canAccess());
        $this->get(route('pdf.trial-balance', ['tenant' => $company->slug]))->assertOk();
    }
}
