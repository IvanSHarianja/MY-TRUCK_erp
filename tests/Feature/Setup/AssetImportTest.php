<?php

namespace Tests\Feature\Setup;

use App\Enums\AccountRole;
use App\Enums\Role;
use App\Filament\Resources\Assets\Pages\ImportAssets;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use App\Services\Import\AssetImportService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import Armada & Alat Berat dari daftar aset perusahaan (struktur meniru
 * "Daftar Aset PT.Berkah Bersama.xlsx": dua tabel dalam satu sheet).
 */
class AssetImportTest extends TestCase
{
    private Company $company;
    private User $owner;
    private AssetImportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        $this->svc = app(AssetImportService::class);
    }

    /** Dua tabel: kendaraan (KETERANGAN = nama) lalu alat berat (NAMA ALAT BERAT). */
    private function sampleFile(): string
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            ['DAFTAR KENDARAAN PT.BERKAH BERSAMA'],
            ['NO', 'NOPOL', 'KETERANGAN', 'TAHUN', 'HARGA BELI', 'NO URUT KEND.'],
            [1, 'R 1975 FS', 'Hino Dutro 130 HT self loader', 2010, '  100,000,000.00 ', 'SL 001'],
            [2, 'H 1393 YE', 'Mitsubishi Canter Dump', 2018, 315000000, 'DT 003'],
            [3, 'B 9711 KYY', 'Hino Dutro 130 HT Dump', 2021, null, 'DT 008'],
            [null, null, null, null, '  415,000,000.00 ', null],            // baris total
            [],
            ['DAFTAR ALAT BERAT PT.BERKAH BERSAMA'],
            ['NO', null, 'NAMA ALAT BERAT', 'TAHUN', 'KETERANGAN', 'HARGA'],
            [1, null, 'Excavator Hyunday XG-60', 2026, 'Beli baru', null],
            [2, null, 'Baby Roller 3T', 2026, 'Beli baru', 'Sudah Di jual'],
            [3, null, 'Dozer D31-px', null, 'Beli second', null],
            [4, null, 'Vibro Sakai SV512D', 2012, 'Beli second', null],
        ], null, 'A1', true);

        $path = tempnam(sys_get_temp_dir(), 'aset') . '.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function row(array $preview, string $name): array
    {
        return collect($preview['rows'])->firstWhere('name', $name) ?? $this->fail("Baris {$name} tidak ada");
    }

    public function test_pratinjau_membaca_dua_tabel_dan_melewati_baris_total(): void
    {
        $p = $this->svc->preview($this->company->id, $this->sampleFile());

        $this->assertSame(7, $p['summary']['total']);
        $this->assertSame(7, $p['summary']['import']);
        $this->assertNull($p['account_error']);

        $sl = $this->row($p, 'Hino Dutro 130 HT self loader');
        $this->assertSame('SL 001', $sl['code']);
        $this->assertSame('R 1975 FS', $sl['plate']);
        $this->assertSame(100_000_000, $sl['price'], 'harga teks "100,000,000.00" terbaca');
        $this->assertSame('2010-07-01', $sl['purchase_date'], 'hanya tahun → 1 Juli');
        $this->assertTrue($sl['date_estimated']);
        $this->assertSame(AssetImportService::DEFAULT_LIFE_MONTHS, $sl['life_months']);

        $dt = $this->row($p, 'Mitsubishi Canter Dump');
        $this->assertSame('dump_truck', $dt['type']);
        $this->assertSame('ARMD', $dt['bu']);
        $this->assertSame([], $dt['missing']);

        $this->assertSame(['harga beli'], $this->row($p, 'Hino Dutro 130 HT Dump')['missing']);
    }

    public function test_alat_berat_tanpa_kode_dibuatkan_kode_dan_jenisnya_ditebak(): void
    {
        $p = $this->svc->preview($this->company->id, $this->sampleFile());

        $exc = $this->row($p, 'Excavator Hyunday XG-60');
        $this->assertSame('EXC-001', $exc['code']);
        $this->assertTrue($exc['code_generated']);
        $this->assertSame('excavator', $exc['type']);
        $this->assertSame('RENT', $exc['bu']);

        $dozer = $this->row($p, 'Dozer D31-px');
        $this->assertSame('bulldozer', $dozer['type']);
        $this->assertSame(['harga beli', 'tanggal beli'], $dozer['missing']);

        // Roller/vibro di tabel alat berat: jenis "Lainnya" tapi lini bisnis tetap RENT.
        $vibro = $this->row($p, 'Vibro Sakai SV512D');
        $this->assertSame('lainnya', $vibro['type']);
        $this->assertSame('RENT', $vibro['bu']);
    }

    public function test_sudah_dijual_jadi_non_aktif_tanpa_tanda_kurang(): void
    {
        $roller = $this->row($this->svc->preview($this->company->id, $this->sampleFile()), 'Baby Roller 3T');

        $this->assertSame('non_aktif', $roller['status']);
        $this->assertSame([], $roller['missing']);
        $this->assertStringContainsString('Sudah dijual', $roller['notes']);
    }

    public function test_simpan_lalu_import_ulang_tidak_menggandakan(): void
    {
        $file = $this->sampleFile();
        $r = $this->svc->commit($this->company->id, $file);

        $this->assertSame(7, $r['imported']);
        $this->assertSame(4, $r['incomplete'], 'DT 008, Excavator, Dozer, Vibro');

        $dt = Asset::where('asset_code', 'DT 003')->firstOrFail();
        $this->assertSame(315_000_000, $dt->purchase_price);
        $this->assertSame('straight_line', $dt->depreciation_method->value);
        $this->assertSame(96, $dt->useful_life_months);
        $this->assertNotNull($dt->account_id, 'akun aset tetap terisi dari Peran');
        $this->assertSame('ARMD', $dt->defaultBusinessUnit->code);

        $again = $this->svc->commit($this->company->id, $file);
        $this->assertSame(0, $again['imported']);
        $this->assertSame(7, $again['skipped']);
        $this->assertSame(7, Asset::count());
    }

    public function test_tanda_perlu_dilengkapi_hilang_setelah_diisi(): void
    {
        $this->svc->commit($this->company->id, $this->sampleFile());
        $dozer = Asset::where('name', 'Dozer D31-px')->firstOrFail();
        $this->assertStringContainsString('Perlu dilengkapi: harga beli, tanggal beli.', $dozer->notes);

        $dozer->update(['purchase_price' => 450_000_000]);
        $this->assertStringContainsString('Perlu dilengkapi: tanggal beli.', $dozer->fresh()->notes);

        $dozer->update(['purchase_date' => '2019-03-15']);
        $this->assertStringNotContainsString('Perlu dilengkapi', (string) $dozer->fresh()->notes);
        $this->assertStringContainsString('Beli second', (string) $dozer->fresh()->notes);
    }

    public function test_tanpa_akun_aset_tetap_import_ditolak_dengan_pesan(): void
    {
        Account::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereIn('role', [AccountRole::FixedAssetArmada->value, AccountRole::FixedAssetKendaraan->value])
            ->update(['role' => null, 'code' => \DB::raw("'9' || code")]);

        $file = $this->sampleFile();
        $this->assertNotNull($this->svc->preview($this->company->id, $file)['account_error']);

        $this->expectException(ValidationException::class);
        $this->svc->commit($this->company->id, $file);
    }

    public function test_file_tanpa_tabel_aset_ditolak(): void
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([['NAMA', 'ALAMAT'], ['PT Maju', 'Tegal']]);
        $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        (new Xlsx($ss))->save($path);

        $this->expectException(ValidationException::class);
        $this->svc->preview($this->company->id, $path);
    }

    public function test_angka_rupiah_berbagai_format(): void
    {
        $this->assertSame(100_000_000, $this->svc->money('100,000,000.00'));
        $this->assertSame(100_000_000, $this->svc->money('Rp 100.000.000'));
        $this->assertSame(100_000_000, $this->svc->money('100.000.000,00'));
        $this->assertSame(1_500, $this->svc->money('1.500'));
        $this->assertSame(315_000_000, $this->svc->money(315000000.0));
        $this->assertNull($this->svc->money('Sudah Di jual'));
        $this->assertNull($this->svc->money(0));
    }

    public function test_halaman_import_menyimpan_dari_file_pratinjau(): void
    {
        Storage::fake('local');
        Filament::setCurrentPanel('admin');
        Storage::disk('local')->put('asset-imports/pratinjau01.xlsx', file_get_contents($this->sampleFile()));

        Livewire::test(ImportAssets::class)
            ->set('storedFile', 'asset-imports/pratinjau01.xlsx')
            ->assertSee('Import 7 aset')
            ->call('import')
            ->assertRedirect();

        $this->assertSame(7, Asset::count());
        Storage::disk('local')->assertMissing('asset-imports/pratinjau01.xlsx');
    }

    public function test_halaman_import_menolak_path_di_luar_folder_import(): void
    {
        Storage::fake('local');
        Filament::setCurrentPanel('admin');
        Storage::disk('local')->put('rahasia.xlsx', file_get_contents($this->sampleFile()));

        Livewire::test(ImportAssets::class)
            ->set('storedFile', '../rahasia.xlsx')
            ->call('import');

        $this->assertSame(0, Asset::count());
        Storage::disk('local')->assertExists('rahasia.xlsx');
    }

    public function test_viewer_tidak_bisa_membuka_import(): void
    {
        $viewer = $this->createTenantUser($this->company, [], Role::Viewer->value);
        $this->actingAsTenant($viewer, $this->company);

        $this->assertFalse(ImportAssets::canAccess());
    }

    public function test_filter_perlu_dilengkapi_di_daftar_aset(): void
    {
        Filament::setCurrentPanel('admin');
        $this->svc->commit($this->company->id, $this->sampleFile());

        Livewire::test(ListAssets::class)
            ->filterTable('perlu_dilengkapi', true)
            ->assertCountTableRecords(4);
    }
}
