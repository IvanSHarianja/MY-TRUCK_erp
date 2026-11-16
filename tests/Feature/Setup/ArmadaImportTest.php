<?php

namespace Tests\Feature\Setup;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Vendor;
use App\Services\CoaImportService;
use App\Services\Import\PartyImportService;
use App\Support\AccountRoleReadiness;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import data master milik klien (format "berlabel", contoh file PT Armada):
 * COA dengan judul KODE/NAMA AKUN/TYPE di baris mana pun, serta daftar
 * klien/vendor (sheet Kode Bantu atau daftar supplier).
 */
class ArmadaImportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/armada-import');
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.xlsx') ?: []);
        parent::tearDown();
    }

    /** @param array<string, list<list<mixed>>> $sheets  nama sheet → baris (mulai A1) */
    private function xlsx(array $sheets, string $name): string
    {
        $ss = new Spreadsheet();
        $ss->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $sh = $ss->createSheet()->setTitle($title);
            $sh->fromArray($rows, null, 'A1', true);
        }
        (new Xlsx($ss))->save($path = "{$this->dir}/{$name}");

        return $path;
    }

    private function armadaCoaRows(): array
    {
        $h = ['', 'KODE', 'NAMA AKUN', 'TYPE', 'Katagori', 'Sub-Katagori', 'Saldo normal', 'Arus kas', 'Peran'];

        return [
            [],
            ['', 'DAFTAR AKUN'],
            [],
            $h,
            [],
            ['', 110000, 'ASET LANCAR', 'Header'],
            ['', 111000, 'KAS', 'Header', 'Aset', 'Aset lancar', 'Debit', 'Operasi', 'Kas & bank utama'],
            ['', 111001, 'Cash On Hand', 'Child', 'Aset', 'Aset lancar', 'Debit', 'Operasi', 'Kas & bank utama'],
            ['', 111005, 'Nama Kas'],                                                   // slot cadangan
            ['', 113000, 'PIUTANG', 'Header', 'Aset', 'Aset lancar', 'Debit', 'Operasi', 'Piutang'],
            ['', 113001, 'Piutang Usaha', 'Child', 'Aset', 'Aset lancar', 'Debit', 'Operasi', 'Piutang usaha'],
            ['', 114002, 'Persediaan Spart Part Alat Berat', 'Child', 'Aset', 'Aset lancar', 'Debit', 'Operasi', 'Persediaan'],
            ['', 120000, 'AKTIVA TETAP', 'Header'],
            ['', 122008, 'ALAT BERAT EXCAVATOR XG-60', 'Child', 'Aset', 'Aktiva Tetap', 'Debit', 'Investasi', 'Aktiva tetap'],
            ['', 122023, 'Akum. Penyusutan Peralatan Kantor', 'Child', '', 'Akumulasi depresiasi', 'Kredit', 'Investasi', ''],
            ['', 400000, 'PENDAPATAN', 'Header'],
            ['', 400003, 'Pendapatan Sewa Alat Berat', 'Child', 'Pendapatan', 'Pendapatan', 'Kredit', 'Operasi', 'Pendapatan'],
            ['', 500000, 'BIAYA POKOK PENJUALAN', 'Header'],
            ['', 510001, 'Biaya Solar Alat Berat', 'Child', 'Biaya', 'Biaya Pokok Penjualan', 'Debit', 'Operasi', 'Biaya'],
        ];
    }

    // ── COA ─────────────────────────────────────────────────────────────

    public function test_coa_berlabel_hierarki_kategori_dan_slot_cadangan(): void
    {
        $company = Company::factory()->create();
        $svc     = app(CoaImportService::class);
        $count   = $svc->seedFromExcel($company, $this->xlsx(['COA' => $this->armadaCoaRows()], 'coa.xlsx'));

        $this->assertSame(13, $count, '14 baris ber-TYPE minus 0; slot "Nama Kas" dilewati');
        $this->assertSame(1, $svc->report['skipped_slots']);

        $acc = fn (string $code) => Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->first();

        $this->assertSame('111000', $acc('111001')->parent_code, 'Induk dari hierarki kode');
        $this->assertSame('110000', $acc('111000')->parent_code);
        $this->assertNull($acc('110000')->parent_code);
        $this->assertSame('aset', $acc('110000')->category, 'Header puncak tanpa kategori → dari anaknya');

        $akum = $acc('122023');
        $this->assertSame(['aset', 'aset_tetap', 'kredit', '120000'], [$akum->category, $akum->sub_category, $akum->normal_balance, $akum->parent_code]);

        $this->assertSame('beban_hpp', $acc('510001')->sub_category);
        $this->assertSame('investasi', $acc('122008')->cash_flow_category);
    }

    public function test_peran_dari_kolom_peran_dan_tebakan_yang_aman_saja(): void
    {
        $company = Company::factory()->create();
        $svc = app(CoaImportService::class);
        $svc->seedFromExcel($company, $this->xlsx(['COA' => $this->armadaCoaRows()], 'coa.xlsx'));
        $acc = fn (string $code) => Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->first();

        $this->assertSame(AccountRole::Cash, $acc('111001')->role, 'Kolom Peran persis label MY-TRUCK');
        $this->assertNull($acc('111001')->notes);
        $this->assertSame(AccountRole::ReceivableUsaha, $acc('113001')->role);

        $this->assertSame(AccountRole::FixedAssetArmada, $acc('122008')->role, 'Tebakan nama yang cocok sub-kategori & saldo normal');
        $this->assertStringContainsString('mohon periksa', $acc('122008')->notes);
        $this->assertSame(AccountRole::RevenueRent, $acc('400003')->role);

        $this->assertNull($acc('114002')->role, 'Persediaan (aset lancar) ≠ Aset Tetap Armada walau nama memuat "alat berat"');
        $this->assertNull($acc('122023')->role, 'Akumulasi (kredit) ≠ Aset Tetap Peralatan (debit)');
        $this->assertNull($acc('111000')->role, 'Header tidak diberi peran');
    }

    public function test_kesiapan_peran_menyebut_fitur_yang_belum_bisa_jurnal(): void
    {
        $company = Company::factory()->create();
        app(CoaImportService::class)->seedFromExcel($company, $this->xlsx(['COA' => $this->armadaCoaRows()], 'coa.xlsx'));

        $missing = AccountRoleReadiness::missing($company->id);

        $this->assertArrayNotHasKey('Inti (invoice & pembayaran)', $missing, 'Kas sub-akun + Piutang Usaha sudah ada');
        $this->assertContains('Pendapatan Ritase Dump Truck (ARMD)', $missing['Kontrak Armada']);
        $this->assertNotContains('Pendapatan Sewa Alat Berat (RENT)', $missing['Rental Alat Berat']);
        $this->assertStringContainsString('Kontrak Armada', AccountRoleReadiness::summary($company->id));

        // Template standar lengkap → tidak ada peringatan.
        $this->assertSame([], AccountRoleReadiness::missing($this->createTenant()->id));
    }

    public function test_template_standar_lama_tetap_berjalan_dan_format_asing_ditolak_jelas(): void
    {
        $company = Company::factory()->create();
        $count = app(CoaImportService::class)->seedFromExcel($company, $this->xlsx(['Sheet1' => [
            ['Kode', 'Nama Akun', 'Kategori', 'Kode Parent'],
            ['111100', 'Kas dan Bank', 'aset', null],
        ]], 'std.xlsx'));
        $this->assertSame(1, $count);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Format Excel tidak dikenali');
        app(CoaImportService::class)->parseAndValidate($this->xlsx(['X' => [['Foo', 'Bar'], ['1', '2']]], 'asing.xlsx'));
    }

    // ── Klien & Vendor ──────────────────────────────────────────────────

    private function kodeBantuFile(): string
    {
        return $this->xlsx([
            'COA' => $this->armadaCoaRows(),                                   // harus dilewati
            'BP'  => [
                [], ['', 'KODE BANTU'], [],
                ['', 'KODE', 'KETERANGAN ', 'JENIS'],
                ['', '', 'PIUTANG USAHA  MATERIAL'],                           // judul bagian
                ['', 'MT-001', 'Proyek Bale Hinggil', 'PIUTANG'],
                ['', 'MT-002', 'Proyek Legal Shamara', 'PIUTANG'],
                ['', 'MT-003', '', 'PIUTANG'],                                  // slot kosong
                ['', 'HP-001', 'Toko Besi Jaya', 'HUTANG'],
                ['', 'KR-001', 'Budi (karyawan)', ''],                          // bukan klien/vendor
            ],
        ], 'bp.xlsx');
    }

    public function test_import_klien_dari_kode_bantu_hanya_piutang_dan_aman_diulang(): void
    {
        $company = $this->createTenant();
        $svc = app(PartyImportService::class);

        $r = $svc->import('client', $company->id, $this->kodeBantuFile());

        $this->assertSame(['imported' => 2, 'skipped_existing' => 0, 'skipped_other_type' => 2, 'skipped_invalid' => 0, 'sheet' => 'BP'], $r);
        $this->assertSame(['MT-001', 'MT-002'], Client::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('code')->pluck('code')->all());

        $again = $svc->import('client', $company->id, $this->kodeBantuFile());
        $this->assertSame(0, $again['imported']);
        $this->assertSame(2, $again['skipped_existing'], 'Import ulang tidak menggandakan data');

        // Regresi file asli: "PIUTANG" mengandung "utang" → klien tidak boleh masuk vendor.
        $v = $svc->import('vendor', $company->id, $this->kodeBantuFile());
        $this->assertSame(1, $v['imported']);
        $this->assertSame(['Toko Besi Jaya'], Vendor::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'HP-001')->pluck('name')->all());
        $this->assertFalse(Vendor::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MT-001')->exists());
    }

    public function test_import_vendor_daftar_supplier_tanpa_kode(): void
    {
        $company = $this->createTenant();
        Vendor::withoutGlobalScopes()->create(['company_id' => $company->id, 'code' => 'VD-007', 'name' => 'Vendor Lama', 'is_active' => true]);

        $file = $this->xlsx(['Sheet1' => [
            ['DAFTAR SUPLIER TERAVIA'], [],
            ['NO', 'NAMA', 'ALAMAT', 'NO.TELP', 'KETERANGAN'],
            [1, 'AGO BAN', 'Grendeng PWT', 6281327754477, 'Ban Dump Truck'],
            [2, 'Top Teknik', 'Langgongsari', 6285201209137, 'Bengkel Bubut'],
            [3, 'vendor lama', 'Purwokerto', null, 'Duplikat nama'],
        ]], 'teravia.xlsx');

        $r = app(PartyImportService::class)->import('vendor', $company->id, $file);
        $this->assertSame(2, $r['imported']);
        $this->assertSame(1, $r['skipped_existing'], 'Nama sama (beda huruf besar) dilewati');

        $ago = Vendor::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'AGO BAN')->first();
        $this->assertSame(['VD-008', 'Grendeng PWT', '6281327754477', 'sparepart'], [$ago->code, $ago->address, $ago->phone, $ago->type],
            'Kode otomatis melanjutkan VD-007; telepon tidak jadi notasi ilmiah');
        $this->assertSame('jasa', Vendor::withoutGlobalScopes()->where('name', 'Top Teknik')->value('type'));
    }

    /** Regresi file asli Teravia: satu sheet berisi tabel SUPLIER lalu tabel KONSUMEN. */
    public function test_satu_sheet_dua_tabel_dipisah_per_judul(): void
    {
        $company = $this->createTenant();
        $file = $this->xlsx(['Sheet1' => [
            ['DAFTAR SUPLIER TERAVIA'], [],
            ['NO', 'NAMA', 'ALAMAT', 'NO.TELP', 'KETERANGAN'],
            [1, 'AGO BAN', 'Grendeng PWT', 6281327754477, 'Ban Dump Truck'],
            [], [],
            ['DAFTAR KONSUMEN TERAVIA'], [],
            ['NO', 'NAMA', 'ALAMAT', 'NO.TELP', 'KETERANGAN'],
            [1, 'Sapphire Madani Purwokerto', 'Purwokerto', null, null],
            [2, 'Sapphire Mejasem', 'Tegal', null, null],
        ]], 'teravia2.xlsx');
        $svc = app(PartyImportService::class);

        $v = $svc->import('vendor', $company->id, $file);
        $c = $svc->import('client', $company->id, $file);

        $this->assertSame([1, 2], [$v['imported'], $v['skipped_other_type']]);
        $this->assertSame([2, 1], [$c['imported'], $c['skipped_other_type']]);
        $this->assertFalse(Vendor::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Sapphire Mejasem')->exists());
        $this->assertTrue(Client::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Sapphire Mejasem')->exists());
    }
}
