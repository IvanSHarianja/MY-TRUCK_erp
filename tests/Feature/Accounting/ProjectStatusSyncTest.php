<?php

namespace Tests\Feature\Accounting;

use App\Models\Project;
use App\Services\Accounting\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression test untuk BUG-BONG-01:
 *
 * Sebelum fix: progress 100% ditolak kalau tertagih_pct < 100% → user harus tagih
 * dulu baru boleh set progress 100%. Terbalik dari real-world konstruksi.
 *
 * Setelah fix: progress 100% BOLEH dicapai kapan saja. Status auto-transisi ke
 * 'selesai' HANYA kalau progress AND tertagih dua-duanya sudah ≥ 100%.
 * Support kedua workflow.
 */
class ProjectStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProjectService::class);
    }

    private function makeProject($company, $user, array $overrides = []): Project
    {
        $client = $this->createClient($company);

        return Project::create(array_merge([
            'company_id'      => $company->id,
            'project_number'  => 'PRJ-' . strtoupper(Str::random(4)),
            'name'            => 'Test Proyek Cut & Fill',
            'client_id'       => $client->id,
            'jenis_pekerjaan' => 'cut_and_fill',
            'nilai_kontrak'   => 100_000_000,
            'progress_pct'    => 0,
            'tertagih_pct'    => 0,
            'dp_diterima'     => 0,
            'status'          => 'berjalan',
            'created_by'      => $user->id,
        ], $overrides));
    }

    // ============================================================
    // Workflow A: TAGIH DULU → PROGRESS 100% (workflow lama, harus tetap jalan)
    // ============================================================

    public function test_workflow_tagih_dulu_kemudian_progress_100_menutup_project(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $project = $this->makeProject($company, $user);

        // Update progress ke 50%
        $this->service->updateProgress($project, 50, 'Galian selesai');
        $project->refresh();
        $this->assertSame('berjalan', $project->status);

        // Tagih termin 1 (50%)
        $this->service->tagihTermin($project, 50);
        $project->refresh();
        $this->assertEquals(50, $project->tertagih_pct);
        $this->assertSame('berjalan', $project->status);

        // Update progress 100% — status masih 'berjalan' karena tertagih baru 50%
        $this->service->updateProgress($project, 100, 'Selesai fisik');
        $project->refresh();
        $this->assertEquals(100, $project->progress_pct);
        $this->assertSame('berjalan', $project->status,
            'Progress 100% saja belum cukup untuk close project — tertagih baru 50%');
        $this->assertNull($project->ended_at);

        // Tagih termin final 50% → tertagih 100% → status auto 'selesai'
        $this->service->tagihTermin($project, 50);
        $project->refresh();
        $this->assertEquals(100, $project->tertagih_pct);
        $this->assertSame('selesai', $project->status,
            'Tagih final saat progress sudah 100% harus auto-close project');
        $this->assertNotNull($project->ended_at);
    }

    // ============================================================
    // Workflow B: PROGRESS 100% DULU → TAGIH FINAL (workflow real-world)
    // ============================================================

    public function test_workflow_progress_100_dulu_kemudian_tagih_final_menutup_project(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $project = $this->makeProject($company, $user);

        // Progress bertahap: 30 → 60 → 100
        $this->service->updateProgress($project, 30);
        $this->service->updateProgress($project->refresh(), 60);

        // Tagih termin 1 (50%) — sesuai progress 60%
        $this->service->tagihTermin($project->refresh(), 50);
        $project->refresh();
        $this->assertEquals(50, $project->tertagih_pct);
        $this->assertSame('berjalan', $project->status);

        // Progress 100% — fisik selesai. Status HARUS tetap 'berjalan' (tertagih 50%)
        $this->service->updateProgress($project, 100, 'Serah terima fisik');
        $project->refresh();
        $this->assertEquals(100, $project->progress_pct);
        $this->assertSame('berjalan', $project->status,
            'Fix BUG-BONG-01: progress 100% TIDAK dilarang saat tertagih < 100%');

        // Tagih termin final 50%
        $this->service->tagihTermin($project, 50);
        $project->refresh();
        $this->assertEquals(100, $project->tertagih_pct);
        $this->assertSame('selesai', $project->status);
        $this->assertNotNull($project->ended_at);
    }

    // ============================================================
    // Guard: progress mundur tetap ditolak
    // ============================================================

    public function test_progress_mundur_tetap_ditolak(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $project = $this->makeProject($company, $user);
        $this->service->updateProgress($project, 50);

        $this->expectException(ValidationException::class);
        $this->service->updateProgress($project->refresh(), 30);
    }

    // ============================================================
    // Guard: progress > 100 ditolak
    // ============================================================

    public function test_progress_diluar_range_ditolak(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $project = $this->makeProject($company, $user);

        $this->expectException(ValidationException::class);
        $this->service->updateProgress($project, 150);
    }

    // ============================================================
    // Idempotency: progress 100% dipanggil 2x saat sudah selesai — no-op
    // ============================================================

    public function test_progress_pada_project_selesai_tidak_ubah_ended_at(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $project = $this->makeProject($company, $user);
        $this->service->updateProgress($project, 100);
        $this->service->tagihTermin($project->refresh(), 100);
        $project->refresh();

        $this->assertSame('selesai', $project->status);
        $endedAt = $project->ended_at->toDateString();

        // Attempt update progress lagi — sistem harus tolak (project sudah selesai)
        // ATAU idempotent — pilihannya sistem tolak karena tagihTermin butuh berjalan
        // dan updateProgress tidak boleh mundur. Kita cek ended_at tidak berubah.
        try {
            $this->service->updateProgress($project, 100);
        } catch (\Throwable $e) {
            // OK if it throws (project already 'selesai')
        }

        $project->refresh();
        $this->assertSame($endedAt, optional($project->ended_at)->toDateString(),
            'ended_at tidak boleh berubah setelah project selesai');
    }
}
