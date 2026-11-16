<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Pages\ContractApprovals;
use App\Filament\Resources\ArmadaContracts\Pages\EditArmadaContract;
use App\Models\ArmadaContract;
use App\Models\Company;
use App\Models\ContractChangeRequest;
use App\Models\User;
use App\Services\ContractChangeService;
use App\Support\RoleMatrix;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Perubahan kontrak wajib persetujuan Owner (dengan alasan). */
class ContractApprovalTest extends TestCase
{
    private Company $company;
    private User $owner;
    private User $admin;
    private ArmadaContract $contract;
    private ContractChangeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->admin   = $this->createTenantUser($this->company, [], 'admin');
        $this->actingAsTenant($this->admin, $this->company);
        $this->svc = app(ContractChangeService::class);

        $this->contract = ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA26-0001',
            'client_id' => $this->createClient($this->company)->id, 'tipe_kontrak' => 'alat_saja',
            'include_bbm' => false, 'include_operator' => false,   // seperti diisi form saat tipe dipilih
            'route_description' => 'Kuari → Proyek', 'tarif_per_rit' => 250_000, 'billed_rit' => 0,
            'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->owner->id,
        ]);
    }

    private function formData(array $override = []): array
    {
        return array_merge([
            'client_id' => $this->contract->client_id, 'started_at' => '2026-01-01', 'status' => 'aktif',
            'route_description' => 'Kuari → Proyek', 'tarif_per_rit' => '250000', 'tipe_kontrak' => 'alat_saja', 'notes' => null,
        ], $override);
    }

    public function test_hanya_owner_yang_default_boleh_menyetujui(): void
    {
        $this->assertTrue(RoleMatrix::has(Role::Owner, Permission::ContractApprove));
        $this->assertFalse(RoleMatrix::has(Role::Admin, Permission::ContractApprove));
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::ContractApprove));
    }

    public function test_admin_mengajukan_kontrak_tidak_berubah_sampai_disetujui(): void
    {
        $req = $this->svc->submit($this->contract, $this->formData(['tarif_per_rit' => '300000']), 'Harga naik per Nov', $this->admin);

        $this->assertSame('pending', $req->status);
        $this->assertEquals(['tarif_per_rit' => ['old' => 250000, 'new' => 300000]], $req->changes, 'Field yang sama tidak ikut tercatat');
        $this->assertSame(250_000.0, (float) $this->contract->fresh()->tarif_per_rit);
        $this->assertSame(['Tarif per Rit: Rp 250.000 → Rp 300.000'], $this->svc->describe($req));

        // Pengajuan kedua ditolak selama yang pertama menunggu.
        $this->expectException(ValidationException::class);
        $this->svc->submit($this->contract, $this->formData(['tarif_per_rit' => '310000']), 'Revisi lagi', $this->admin);
    }

    public function test_owner_menyetujui_perubahan_diterapkan_dan_tercatat(): void
    {
        $req = $this->svc->submit($this->contract, $this->formData(['tipe_kontrak' => 'semi', 'tarif_per_rit' => '300000']), 'Klien minta supir dari PT', $this->admin);

        $this->svc->approve($req, $this->owner, 'OK');

        $c = $this->contract->fresh();
        $this->assertSame(300_000.0, (float) $c->tarif_per_rit);
        $this->assertSame('semi', $c->tipe_kontrak);
        $this->assertTrue((bool) $c->include_operator, 'Flag turunan tipe ikut disesuaikan');
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame($this->owner->id, $req->fresh()->reviewed_by);
        $this->assertDatabaseHas('activity_log', ['description' => 'Perubahan kontrak disetujui', 'subject_id' => $c->id]);
    }

    public function test_admin_tidak_bisa_menyetujui(): void
    {
        $req = $this->svc->submit($this->contract, $this->formData(['tarif_per_rit' => '300000']), 'Harga naik', $this->admin);

        $this->expectException(ValidationException::class);
        $this->svc->approve($req, $this->admin);
    }

    public function test_owner_mengubah_langsung_berlaku_tapi_tetap_tercatat_dengan_alasan(): void
    {
        $req = $this->svc->submit($this->contract, $this->formData(['tarif_per_rit' => '275000']), 'Negosiasi ulang', $this->owner);

        $this->assertSame('approved', $req->status);
        $this->assertSame('Negosiasi ulang', $req->reason);
        $this->assertSame(275_000.0, (float) $this->contract->fresh()->tarif_per_rit);
    }

    public function test_pengajuan_basi_ditolak_saat_disetujui(): void
    {
        $req = $this->svc->submit($this->contract, $this->formData(['tarif_per_rit' => '300000']), 'Harga naik', $this->admin);
        $this->contract->update(['tarif_per_rit' => 260_000]);    // berubah lewat jalur lain setelah diajukan

        try {
            $this->svc->approve($req, $this->owner);
            $this->fail('Pengajuan basi seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Tarif per Rit', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(260_000.0, (float) $this->contract->fresh()->tarif_per_rit);
        $this->assertTrue($req->fresh()->isPending());
    }

    public function test_tolak_wajib_alasan_dan_batal_hanya_oleh_pengaju(): void
    {
        $req = $this->svc->submit($this->contract, $this->formData(['tarif_per_rit' => '300000']), 'Harga naik', $this->admin);

        try {
            $this->svc->cancel($req, $this->owner);
            $this->fail('Bukan pengaju tidak boleh membatalkan');
        } catch (ValidationException) {
        }

        $this->svc->reject($req, $this->owner, 'Tunggu kontrak baru');
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame(250_000.0, (float) $this->contract->fresh()->tarif_per_rit);
    }

    public function test_alasan_wajib_dan_tanpa_perubahan_ditolak(): void
    {
        foreach ([[$this->formData(['tarif_per_rit' => '300000']), ''], [$this->formData(), 'Tidak ada yang berubah']] as [$data, $reason]) {
            try {
                $this->svc->submit($this->contract, $data, $reason, $this->admin);
                $this->fail('Harus ditolak');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, ContractChangeRequest::count());
    }

    // ── UI ──────────────────────────────────────────────────────────────

    public function test_halaman_edit_mengajukan_bukan_menyimpan_dan_enter_tidak_bisa_melewati(): void
    {
        Filament::setCurrentPanel('admin');

        Livewire::test(EditArmadaContract::class, ['record' => $this->contract->getRouteKey()])
            ->fillForm(['tarif_per_rit' => '300000'])
            ->call('save')                                  // Enter / Ctrl+S
            ->assertActionMounted('submitChange')
            ->setActionData(['reason' => 'Harga solar naik'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(250_000.0, (float) $this->contract->fresh()->tarif_per_rit, 'Belum berubah sebelum disetujui');
        $this->assertSame(1, ContractChangeRequest::where('status', 'pending')->count());
    }

    /**
     * Regresi KA26-0002: tipe Alat Saja tapi flag include_* di DB = 1 →
     * section BBM/Supir tampil & wajib → Kirim Pengajuan gagal diam-diam.
     */
    public function test_alat_saja_dengan_flag_basi_tidak_menampilkan_biaya_dan_pengajuan_berhasil(): void
    {
        \Illuminate\Support\Facades\DB::table('armada_contracts')->where('id', $this->contract->id)
            ->update(['include_bbm' => true, 'include_operator' => true]);
        Filament::setCurrentPanel('admin');

        Livewire::test(EditArmadaContract::class, ['record' => $this->contract->getRouteKey()])
            ->assertFormFieldHidden('bbm_liter_per_rit')
            ->assertFormFieldHidden('gaji_supir_per_hari')
            ->fillForm(['tarif_per_rit' => '240000'])
            ->callAction('submitChange', ['reason' => 'ada perubahan harga per rit nya'])
            ->assertHasNoFormErrors();

        $this->assertSame(1, ContractChangeRequest::where('status', 'pending')->count());
    }

    public function test_pilih_all_in_menampilkan_biaya_dan_error_validasi_tidak_tersembunyi(): void
    {
        Filament::setCurrentPanel('admin');

        Livewire::test(EditArmadaContract::class, ['record' => $this->contract->getRouteKey()])
            ->fillForm(['tipe_kontrak' => 'all_in'])
            ->assertFormFieldVisible('bbm_liter_per_rit')
            ->assertFormFieldVisible('gaji_supir_per_hari')
            ->callAction('submitChange', ['reason' => 'Jadi all in'])
            ->assertHasFormErrors(['bbm_liter_per_rit', 'gaji_supir_per_hari'])   // tampil di form, bukan tertahan di pop-up
            ->assertActionNotMounted('submitChange')                               // pop-up tertutup
            ->assertNotified('Lengkapi isian kontrak dulu');

        $this->assertSame(0, ContractChangeRequest::count());
    }

    public function test_owner_menyetujui_dari_halaman_persetujuan(): void
    {
        $req = $this->svc->submit($this->contract, $this->formData(['tarif_per_rit' => '300000']), 'Harga naik', $this->admin);
        Filament::setCurrentPanel('admin');
        $this->actingAsTenant($this->owner, $this->company);

        $this->assertSame('1', ContractApprovals::getNavigationBadge());

        Livewire::test(ContractApprovals::class)
            ->assertCanSeeTableRecords([$req])
            ->assertSee('Tarif per Rit: Rp 250.000 → Rp 300.000')
            ->callTableAction('approve', $req, ['note' => 'Setuju']);

        $this->assertSame(300_000.0, (float) $this->contract->fresh()->tarif_per_rit);
    }
}
