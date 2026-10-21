<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Services\CoaImportService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Regression: sub-akun bertingkat gagal disimpan.
 *
 * accounts.code dulu varchar(10). Sub-akun memakai pola {induk}-NN, jadi
 * tingkat kedua (111100-01-01 = 12 karakter) menabrak batas kolom:
 * SQLSTATE[22001] "Data too long for column 'code'".
 *
 * CATATAN: SQLite (dipakai test) TIDAK menegakkan panjang varchar, jadi
 * membuat akun berkode panjang akan lolos walau kolomnya sempit. Karena itu
 * lebar kolom diperiksa langsung lewat skema — itu yang mewakili MySQL.
 */
class AccountCodeLengthTest extends TestCase
{
    private const PANJANG_MINIMAL = 30;

    public function test_batas_kode_cukup_untuk_sub_akun_bertingkat(): void
    {
        // 111100-01-01 = 12 karakter; beri ruang sampai beberapa tingkat lagi.
        $this->assertGreaterThanOrEqual(self::PANJANG_MINIMAL, Account::CODE_MAX_LENGTH);
    }

    public function test_form_dan_import_ikut_satu_batas_yang_sama(): void
    {
        // Akar masalah dulu: kolom 10, form 20, import tanpa batas. Semua harus
        // bersumber dari satu konstanta supaya tidak melenceng lagi.
        $form = file_get_contents(app_path('Filament/Resources/Accounts/Schemas/AccountForm.php'));
        $this->assertStringContainsString('->maxLength(Account::CODE_MAX_LENGTH)', $form,
            'Form COA harus memakai Account::CODE_MAX_LENGTH, bukan angka sendiri');

        $ref = new \ReflectionClass(CoaImportService::class);
        $this->assertSame(Account::CODE_MAX_LENGTH, $ref->getConstant('CODE_MAX_LENGTH'));
    }

    public function test_kolom_database_cukup_lebar(): void
    {
        // SQLite tidak menegakkan panjang varchar, jadi pemeriksaan ini hanya
        // berarti di MySQL — tempat error aslinya muncul.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Lebar varchar hanya ditegakkan MySQL; jalankan di DB MySQL untuk cek ini.');
        }

        foreach (['code', 'parent_code'] as $column) {
            $definisi = collect(Schema::getColumns('accounts'))->firstWhere('name', $column);
            preg_match('/\((\d+)\)/', $definisi['type'], $m);

            $this->assertGreaterThanOrEqual(Account::CODE_MAX_LENGTH, (int) ($m[1] ?? 0),
                "accounts.{$column} lebih sempit dari Account::CODE_MAX_LENGTH");
        }
    }

    public function test_sub_akun_dua_tingkat_bisa_dibuat(): void
    {
        $company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($company), $company);

        $anak = Account::create([
            'company_id' => $company->id, 'code' => '111100-01', 'parent_code' => '111100',
            'name' => 'Bank Mandiri', 'category' => 'aset', 'normal_balance' => 'debit',
            'role' => 'cash', 'is_active' => true,
        ]);
        $cucu = Account::create([
            'company_id' => $company->id, 'code' => '111100-01-01', 'parent_code' => $anak->code,
            'name' => 'Mandiri Giro Operasional', 'category' => 'aset', 'normal_balance' => 'debit',
            'role' => 'cash', 'is_active' => true,
        ]);

        $this->assertSame('111100-01-01', $cucu->fresh()->code);
        // Induk otomatis jadi HEADER, cucu yang bisa dijurnal
        $this->assertTrue($anak->fresh()->isHeader());
        $this->assertTrue($cucu->isPostable());
        // Hierarki tetap terbaca utuh dari akun teratas
        $this->assertContains($cucu->id, Account::descendantIds('111100', $company->id));
    }

    public function test_saran_kode_sub_akun_bertingkat(): void
    {
        $company = $this->createTenant();

        $this->assertSame('111100-01', Account::suggestChildCode('111100', $company->id));

        Account::create([
            'company_id' => $company->id, 'code' => '111100-01', 'parent_code' => '111100',
            'name' => 'Bank Mandiri', 'category' => 'aset', 'normal_balance' => 'debit',
            'role' => 'cash', 'is_active' => true,
        ]);

        $this->assertSame('111100-02', Account::suggestChildCode('111100', $company->id));
        $this->assertSame('111100-01-01', Account::suggestChildCode('111100-01', $company->id),
            'Sub-akun dari sub-akun harus dapat saran kode, bukan gagal');
    }

    public function test_saran_kode_tidak_mengulang_setelah_anak_ke_99(): void
    {
        // Urutan string menganggap '-99' > '-100' → dulu kode ke-101 terulang.
        $company = $this->createTenant();

        foreach (['111100-99', '111100-100'] as $code) {
            Account::create([
                'company_id' => $company->id, 'code' => $code, 'parent_code' => '111100',
                'name' => 'Rekening ' . $code, 'category' => 'aset', 'normal_balance' => 'debit',
                'role' => 'cash', 'is_active' => true,
            ]);
        }

        $this->assertSame('111100-101', Account::suggestChildCode('111100', $company->id));
    }

    public function test_import_excel_menolak_kode_terlalu_panjang(): void
    {
        $company = $this->createTenant();
        $file = $this->buatFileCoa([
            ['Kode', 'Nama Akun', 'Kategori', 'Kode Parent'],
            [str_repeat('1', self::PANJANG_MINIMAL + 1), 'Akun Kepanjangan', 'aset', ''],
        ]);

        try {
            app(CoaImportService::class)->seedFromExcel($company, $file);
            $this->fail('Kode melebihi lebar kolom harus ditolak dengan pesan jelas');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('terlalu panjang', $e->getMessage());
        } finally {
            @unlink($file);
        }
    }

    public function test_import_excel_menerima_sub_akun_bertingkat(): void
    {
        $company = $this->createTenant();
        $file = $this->buatFileCoa([
            ['Kode', 'Nama Akun', 'Kategori', 'Kode Parent'],
            ['111100', 'Kas dan Bank', 'aset', ''],
            ['111100-01', 'Bank Mandiri', 'aset', '111100'],
            ['111100-01-01', 'Mandiri Giro', 'aset', '111100-01'],
        ]);

        try {
            app(CoaImportService::class)->seedFromExcel($company, $file);
        } finally {
            @unlink($file);
        }

        $this->assertNotNull(Account::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('code', '111100-01-01')->first());
    }

    /** @param array<int, array<int, string>> $rows */
    private function buatFileCoa(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'coa_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
