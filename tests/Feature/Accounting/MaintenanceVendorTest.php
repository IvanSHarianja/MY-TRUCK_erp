<?php

namespace Tests\Feature\Accounting;

use App\Enums\MaintenanceType;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\RelationManagers\MaintenanceLogsRelationManager;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Vendor;
use App\Services\Accounting\MaintenanceService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** Nama bengkel/vendor tampil di Riwayat Pemeliharaan & keterangan jurnal kas. */
class MaintenanceVendorTest extends TestCase
{
    private Company $company;
    private Asset $asset;
    private Vendor $bengkel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($this->company), $this->company);
        Filament::setCurrentPanel('admin');

        $this->asset = Asset::create([
            'company_id' => $this->company->id, 'asset_code' => 'DT-01', 'name' => 'Dump Truck 01', 'type' => 'dump_truck',
            'purchase_date' => '2024-01-15', 'purchase_price' => 300_000_000, 'salvage_value' => 0,
            'useful_life_months' => 96, 'status' => 'aktif',
        ]);
        $this->bengkel = Vendor::create([
            'company_id' => $this->company->id, 'code' => 'VD-001', 'name' => 'Bengkel Las Sinar Jaya Abadi Purwokerto',
            'type' => 'jasa', 'phone' => '0812345678', 'is_active' => true,
        ]);
    }

    private function servis(?int $vendorId, string $desc): AssetMaintenanceLog
    {
        return app(MaintenanceService::class)->log([
            'asset_id' => $this->asset->id, 'maintenance_date' => now()->toDateString(),
            'type' => MaintenanceType::ServiceRutin->value, 'description' => $desc,
            'vendor_id' => $vendorId, 'cost' => 350_000,
        ]);
    }

    public function test_riwayat_menampilkan_nama_vendor_lengkap(): void
    {
        $denganVendor = $this->servis($this->bengkel->id, 'Las bak');
        $tanpaVendor  = $this->servis(null, 'Ganti oli sendiri');

        Livewire::test(MaintenanceLogsRelationManager::class, ['ownerRecord' => $this->asset, 'pageClass' => EditAsset::class])
            ->assertCanSeeTableRecords([$denganVendor, $tanpaVendor])
            ->assertTableColumnStateSet('vendor.name', 'Bengkel Las Sinar Jaya Abadi Purwokerto', $denganVendor)
            ->assertSee('Bengkel Las Sinar Jaya Abadi Purwokerto')   // tidak dipotong 20 huruf
            ->assertSee('Tanpa vendor')
            ->filterTable('vendor_id', $this->bengkel->id)
            ->assertCanSeeTableRecords([$denganVendor])
            ->assertCanNotSeeTableRecords([$tanpaVendor]);
    }

    public function test_jurnal_pembayaran_menyebut_vendor(): void
    {
        $log = $this->servis($this->bengkel->id, 'Las bak');

        $kasLine = JournalEntry::withoutGlobalScopes()->with('lines')->find($log->journal_entry_id)
            ->lines->firstWhere('kredit', '>', 0);

        $this->assertStringContainsString('Bengkel Las Sinar Jaya Abadi Purwokerto', $kasLine->description);
    }
}
