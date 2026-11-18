<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Buka setiap halaman list/create resource & setiap halaman custom panel
 * sebagai Owner dan sebagai Viewer: tidak boleh ada error 500.
 * (Owner melihat semua; viewer boleh 403 tapi tidak boleh crash.)
 */
class AllPagesSmokeTest extends TestCase
{
    /** @return list<string> */
    private function urls(): array
    {
        $panel = Filament::getPanel('admin');
        $urls = [];
        foreach ($panel->getResources() as $resource) {
            foreach (['index', 'create'] as $page) {
                if ($resource::hasPage($page)) {
                    $urls[$resource . '::' . $page] = $resource::getUrl($page);
                }
            }
        }
        foreach ($panel->getPages() as $page) {
            try {
                $urls[$page] = $page::getUrl();
            } catch (\Throwable) {
                // halaman dengan parameter wajib — dilewati
            }
        }

        return $urls;
    }

    public function test_semua_halaman_terbuka_tanpa_error(): void
    {
        $company = $this->createTenant();
        foreach (['owner', 'admin', 'accountant', 'viewer'] as $role) {
            $user = $this->createTenantUser($company, [], $role);
            $this->actingAsTenant($user, $company);
            Filament::setCurrentPanel('admin');

            $failures = [];
            $ok = 0;
            foreach ($this->urls() as $name => $url) {
                $status = $this->get($url)->getStatusCode();
                if ($status >= 500 || ($status >= 300 && $status < 400)) {
                    $failures[] = "{$role} {$name} → {$status}";
                }
                $ok += $status === 200 ? 1 : 0;
            }
            $this->assertSame([], $failures, implode("\n", $failures));
            // Pastikan benar-benar merender halaman, bukan dialihkan ke login.
            $this->assertGreaterThan(20, $ok, "{$role}: hanya {$ok} halaman 200");
        }
    }
}
