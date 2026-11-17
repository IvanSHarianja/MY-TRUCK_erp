<?php

namespace Tests\Feature\Setup;

use App\Enums\Role;
use App\Filament\Resources\Clients\Pages\ImportClients;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Vendors\Pages\ImportVendors;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Import\PartyImportService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Import Klien / Vendor dengan pratinjau: dilewati, nama mirip, dan konfirmasi. */
class PartyImportPreviewTest extends TestCase
{
    private Company $company;
    private User $owner;
    private PartyImportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');
        $this->svc = app(PartyImportService::class);

        Client::create(['company_id' => $this->company->id, 'code' => 'KL-004', 'name' => 'PT. Maju Jaya', 'is_active' => true]);
    }

    private function file(): string
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            ['DAFTAR KONSUMEN'],
            ['NO', 'NAMA', 'ALAMAT', 'NO.TELP'],
            [1, 'PT Maju Jaya', 'Tegal', null],                 // sama (beda titik) → dilewati
            [2, 'CV Maju Jaya', 'Brebes', 6281327754477],       // mirip → peringatan
            [3, 'Sapphire Mejasem', 'Tegal', null],             // baru
            [4, 'sapphire  mejasem', 'Tegal', null],            // ganda di file → dilewati
        ], null, 'A1', true);

        $path = tempnam(sys_get_temp_dir(), 'pt') . '.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    public function test_pratinjau_tidak_menyimpan_dan_menandai_sama_mirip_baru(): void
    {
        $p = $this->svc->preview('client', $this->company->id, $this->file());

        $this->assertSame(1, Client::count(), 'pratinjau tidak menyimpan apa pun');
        $this->assertSame(['total' => 4, 'import' => 2, 'skip' => 2, 'similar' => 1, 'other_type' => 0, 'invalid' => 0], $p['summary']);

        [$same, $similar, $new, $dup] = $p['rows'];
        $this->assertStringContainsString('Nama sudah ada (PT. Maju Jaya)', $same['skip']);
        $this->assertNull($similar['skip']);
        $this->assertStringContainsString('Mirip dengan "PT. Maju Jaya"', $similar['warning']);
        $this->assertSame('KL-005', $similar['final_code'], 'kode otomatis melanjutkan KL-004');
        $this->assertSame('KL-006', $new['final_code']);
        $this->assertNull($new['warning']);
        $this->assertNotNull($dup['skip'], 'nama ganda di dalam file yang sama dilewati');
    }

    public function test_import_sesuai_pratinjau(): void
    {
        $r = $this->svc->import('client', $this->company->id, $this->file());

        $this->assertSame(2, $r['imported']);
        $this->assertSame(2, $r['skipped_existing']);
        $this->assertSame(['KL-004', 'KL-005', 'KL-006'], Client::orderBy('code')->pluck('code')->all());
    }

    public function test_halaman_import_klien_menyimpan_setelah_konfirmasi(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('party-imports/klien01.xlsx', file_get_contents($this->file()));

        Livewire::test(ImportClients::class)
            ->set('storedFile', 'party-imports/klien01.xlsx')
            ->assertSee('Import 2 klien')
            ->assertSee('Mirip dengan')
            ->call('import')
            ->assertRedirect();

        $this->assertSame(3, Client::count());
        Storage::disk('local')->assertMissing('party-imports/klien01.xlsx');
    }

    public function test_halaman_vendor_menampilkan_jenis_vendor(): void
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            ['NO', 'NAMA', 'ALAMAT', 'KETERANGAN'],
            [1, 'AGO BAN', 'Purwokerto', 'Ban Dump Truck'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'vd') . '.xlsx';
        (new Xlsx($ss))->save($path);

        Storage::fake('local');
        Storage::disk('local')->put('party-imports/vendor01.xlsx', file_get_contents($path));

        Livewire::test(ImportVendors::class)
            ->set('storedFile', 'party-imports/vendor01.xlsx')
            ->assertSee('Sparepart')
            ->call('import');

        $this->assertSame('sparepart', Vendor::where('name', 'AGO BAN')->value('type'));
    }

    public function test_path_di_luar_folder_import_ditolak(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('party-imports/../lain.xlsx', file_get_contents($this->file()));

        Livewire::test(ImportClients::class)
            ->set('storedFile', 'party-imports/../lain.xlsx')
            ->call('import');

        $this->assertSame(1, Client::count());
    }

    public function test_tombol_dan_halaman_import_butuh_izin_master_data(): void
    {
        $viewer = $this->createTenantUser($this->company, [], Role::Viewer->value);
        $this->actingAsTenant($viewer, $this->company);

        $this->assertFalse(ImportClients::canAccess());
        $this->assertFalse(ImportVendors::canAccess());
        Livewire::test(ListClients::class)->assertActionHidden('importExcel');
    }
}
