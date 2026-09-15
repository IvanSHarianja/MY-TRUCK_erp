<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Support\UserGuide;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Panduan Pengguna sebagai pop-up (slide-over), bukan halaman terpisah:
 *  - global search diganti tombol Panduan di topbar
 *  - ikon "?" di header tiap halaman membuka pop-up di bagian terkait
 *  - modal dipasang kosong; isi di-fetch dari route panduan.konten saat dibuka
 */
class UserGuideTest extends TestCase
{
    /** @return array{0: Company, 1: User} */
    private function tenant(string $role = 'owner'): array
    {
        $company = $this->createTenant();

        return [$company, $this->createTenantUser($company, [], $role)];
    }

    public function test_global_search_dimatikan(): void
    {
        $this->assertNull(Filament::getPanel('admin')->getGlobalSearchProvider());

        [$company, $user] = $this->tenant();
        $this->actingAs($user)
            ->get("/admin/{$company->slug}")
            ->assertOk()
            ->assertDontSee('fi-global-search', false);
    }

    public function test_topbar_membuka_popup_panduan_tanpa_pindah_halaman(): void
    {
        [$company, $user] = $this->tenant();

        $this->actingAs($user)
            ->get("/admin/{$company->slug}")
            ->assertOk()
            ->assertSee('panduan-topbar-button', false)
            ->assertSee("\$dispatch('panduan-buka'", false)
            // Modal ada di halaman, isinya belum (lazy — diambil saat dibuka)
            ->assertSee('id="panduan-modal"', false)
            // URL fetch di-encode @js (garis miring jadi \/)
            ->assertSee(str_replace('/', '\/', route('panduan.konten')), false)
            ->assertDontSee('id="panduan-memulai"', false)
            ->assertDontSee("/admin/{$company->slug}/panduan", false);
    }

    public function test_ikon_bantuan_membuka_popup_di_bagian_halaman_itu(): void
    {
        [$company, $user] = $this->tenant();
        $this->actingAs($user);

        // Halaman biasa (Page) — scope = class Page
        $this->get("/admin/{$company->slug}/balance-sheet")
            ->assertOk()
            ->assertSee('panduan-help-button', false)
            ->assertSee('data-panduan-key="laporan-keuangan"', false);

        // Halaman resource — scope = class Page + class Resource
        $this->get("/admin/{$company->slug}/journal-entries")
            ->assertOk()
            ->assertSee('data-panduan-key="jurnal-umum"', false);
    }

    public function test_halaman_panduan_lama_sudah_tidak_ada(): void
    {
        [$company, $user] = $this->tenant();

        $this->actingAs($user)->get("/admin/{$company->slug}/panduan")->assertNotFound();
    }

    public function test_isi_popup_bisa_dimuat_semua_role_dan_memuat_semua_bagian(): void
    {
        [, $viewer] = $this->tenant(Role::Viewer->value);

        $response = $this->actingAs($viewer)->get(route('panduan.konten'))->assertOk();

        foreach (UserGuide::SECTIONS as $section) {
            $response->assertSee('id="panduan-' . $section['key'] . '"', false)
                ->assertSee($section['title']);
        }
        // Tabel Markdown (GFM) ter-render sebagai HTML, bukan teks mentah "|---|"
        $response->assertSee('<table>', false)->assertDontSee('|---|', false);
        // Fragment saja — bukan halaman panel lengkap
        $response->assertDontSee('<html', false);
    }

    public function test_isi_popup_butuh_login(): void
    {
        $this->get(route('panduan.konten'))->assertUnauthorized();
    }

    public function test_popup_tidak_dipasang_di_halaman_login(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('id="panduan-modal"', false);
    }

    public function test_setiap_resource_dan_halaman_panel_punya_bagian_panduan(): void
    {
        // Guard: modul baru wajib didaftarkan di UserGuide::SECTIONS['pages'],
        // supaya ikon "?" muncul dan panduannya tidak tertinggal.
        $panel = Filament::getPanel('admin');

        foreach ($panel->getResources() as $resource) {
            $this->assertNotNull(UserGuide::sectionFor([$resource]), "Resource {$resource} belum punya bagian panduan");
        }
        foreach ($panel->getPages() as $page) {
            $this->assertNotNull(UserGuide::sectionFor([$page]), "Page {$page} belum punya bagian panduan");
        }
    }

    public function test_setiap_bagian_punya_isi_dan_key_unik(): void
    {
        $keys = array_column(UserGuide::SECTIONS, 'key');
        $this->assertSame($keys, array_unique($keys), 'Key bagian panduan harus unik (dipakai sebagai anchor)');

        foreach (UserGuide::SECTIONS as $section) {
            $this->assertFileExists(resource_path("panduan/{$section['key']}.md"));
            $this->assertNotEmpty(trim((string) UserGuide::html($section['key'])), "Isi panduan {$section['key']} kosong");

            foreach ($section['pages'] as $class) {
                $this->assertTrue(class_exists($class), "Class {$class} di bagian {$section['key']} tidak ada");
            }
        }
    }

    public function test_tautan_antar_bagian_tidak_putus(): void
    {
        $keys = array_column(UserGuide::SECTIONS, 'key');

        foreach (glob(resource_path('panduan/*.md')) as $file) {
            preg_match_all('/\]\(#([a-z0-9-]+)\)/', file_get_contents($file), $matches);
            foreach ($matches[1] as $anchor) {
                $this->assertContains($anchor, $keys, basename($file) . " menaut ke #{$anchor} yang tidak ada");
            }
        }

        // Tautan Markdown ter-render jadi <a href="#..."> (tidak di-strip oleh mode aman)
        $this->assertStringContainsString('href="#coa-paket"', (string) UserGuide::html('daftar-akun'));
    }
}
