<?php

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use App\Services\CoaImportService;
use App\Services\Import\AssetImportService;
use App\Support\AccountRoleReadiness;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Peringatan COA & import harus berkurang begitu datanya diperbaiki
 * (masukan 2026-11-20: "COA sudah diupdate, notifikasi tidak berkurang").
 */
class AlertsShrinkAfterUpdateTest extends TestCase
{
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');
    }

    private function guessed(string $code): Account
    {
        $a = $this->postableAccount($this->company, $code);
        $a->update(['notes' => CoaImportService::GUESSED_ROLE_NOTE]);

        return $a->fresh();
    }

    public function test_persediaan_solar_sebagai_cadangan_tidak_dianggap_kurang(): void
    {
        // PT lama: tanpa akun ber-peran Persediaan Material, fitur memakai Persediaan Solar.
        Account::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('role', AccountRole::InventoryMaterial->value)->update(['role' => null, 'code' => '119999']);
        $this->assertNotNull(Account::materialInventoryAccount($this->company->id), 'fitur tetap jalan lewat cadangan');

        $this->assertArrayNotHasKey('Material', AccountRoleReadiness::missing($this->company->id));
    }

    public function test_ganti_peran_menghapus_tanda_tebakan(): void
    {
        $a = $this->guessed('441200');
        $a->update(['role' => AccountRole::RevenueRent->value]);
        $this->assertNull($a->fresh()->notes);
    }

    public function test_simpan_lewat_form_edit_menghapus_tanda_tebakan_catatan_lain_tetap(): void
    {
        $a = $this->guessed('441300');
        $a->update(['notes' => CoaImportService::GUESSED_ROLE_NOTE . ' Akun lama dari Accurate.']);

        Livewire::test(EditAccount::class, ['record' => $a->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Akun lama dari Accurate.', $a->fresh()->notes);
    }

    public function test_tombol_peran_benar_dan_aksi_massal(): void
    {
        $a = $this->guessed('441100');
        $b = $this->guessed('441400');
        $c = $this->guessed('441900');

        Livewire::test(ListAccounts::class)->callAction(TestAction::make('confirm_role')->table($a));
        $this->assertNull($a->fresh()->notes);

        Livewire::test(ListAccounts::class)->callTableBulkAction('confirm_roles', [$b, $c]);
        $this->assertNull($b->fresh()->notes);
        $this->assertNull($c->fresh()->notes);
        $this->assertSame(0, Account::where('notes', 'like', CoaImportService::GUESSED_ROLE_PREFIX . '%')->count());
    }

    public function test_aset_impor_data_benar_menghapus_tanda_perlu_dilengkapi(): void
    {
        $asset = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-1', 'name' => 'Dump', 'type' => 'dump_truck',
            'purchase_date' => '2020-07-01', 'purchase_price' => 300_000_000, 'salvage_value' => 0, 'useful_life_months' => 96,
            'status' => 'aktif', 'notes' => AssetImportService::INCOMPLETE_MARKER . ' tanggal beli. Diimpor dari Excel (baris 5).',
        ]);

        Livewire::test(ListAssets::class)->callAction(TestAction::make('mark_complete')->table($asset));

        $this->assertStringNotContainsString(AssetImportService::INCOMPLETE_MARKER, (string) $asset->fresh()->notes);
        $this->assertStringContainsString('Diimpor dari Excel', (string) $asset->fresh()->notes);
    }
}
