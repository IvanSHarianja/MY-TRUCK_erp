<?php

namespace Tests\Feature\Setup;

use App\Models\Account;
use App\Models\Company;
use App\Services\CoaImportService;
use App\Support\CoaTemplateExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CoaExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private CoaImportService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CoaImportService::class);
        $this->tmpDir = storage_path('framework/testing/coa-tests');
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Cleanup file uji
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*.xlsx') as $f) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    /**
     * Bikin file Excel di temp dari array rows. Row 0 adalah header.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    private function makeXlsx(array $rows, string $name = 'test.xlsx'): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $val) {
                // Column letter dari index (0→A, 1→B, ...). Cukup untuk 4 kolom.
                $colLetter = chr(ord('A') + $c);
                $sheet->setCellValue($colLetter . ($r + 1), $val);
            }
        }
        $path = $this->tmpDir . '/' . $name;
        (new Xlsx($ss))->save($path);
        return $path;
    }

    /** Header yang benar */
    private function header(): array
    {
        return ['Kode', 'Nama Akun', 'Kategori', 'Kode Parent'];
    }

    public function test_happy_path_parses_valid_rows(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111000', 'Kas dan Bank', 'aset', ''],
            ['111100', 'Kas Besar', 'aset', '111000'],
            ['441100', 'Pendapatan Sewa', 'pendapatan', ''],
        ]);

        $rows = $this->service->parseAndValidate($path);

        $this->assertCount(3, $rows);
        $this->assertSame('111000', $rows[0]['code']);
        $this->assertSame('aset', $rows[0]['category']);
        $this->assertNull($rows[0]['parent_code']);
        $this->assertSame('111000', $rows[1]['parent_code']);
    }

    public function test_empty_file_throws(): void
    {
        $path = $this->makeXlsx([$this->header()]);

        $this->expectException(ValidationException::class);
        $this->service->parseAndValidate($path);
    }

    public function test_wrong_header_throws(): void
    {
        $path = $this->makeXlsx([
            ['Code', 'Name', 'Category', 'Parent'], // English — salah
            ['111000', 'Kas', 'aset', ''],
        ]);

        $this->expectException(ValidationException::class);
        $this->service->parseAndValidate($path);
    }

    public function test_empty_code_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['', 'Kas', 'aset', ''],
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Kolom \'Kode\' kosong', $e->getMessage());
        }
    }

    public function test_invalid_category_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111100', 'Kas', 'income', ''], // bukan salah satu enum
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Kategori \'income\' invalid', $e->getMessage());
        }
    }

    public function test_duplicate_code_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111100', 'Kas A', 'aset', ''],
            ['111100', 'Kas B', 'aset', ''],
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplikat', $e->getMessage());
        }
    }

    public function test_parent_code_not_in_file_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111100', 'Kas Besar', 'aset', '111000'], // 111000 tidak ada di file
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Kode Parent \'111000\'', $e->getMessage());
        }
    }

    public function test_self_reference_parent_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111100', 'Kas', 'aset', '111100'], // parent dirinya sendiri
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak boleh menjadi parent dari dirinya sendiri', $e->getMessage());
        }
    }

    public function test_cyclic_reference_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['A', 'Akun A', 'aset', 'B'],
            ['B', 'Akun B', 'aset', 'C'],
            ['C', 'Akun C', 'aset', 'A'], // A → B → C → A
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('siklik', $e->getMessage());
        }
    }

    public function test_parent_category_mismatch_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111000', 'Kas Parent', 'aset', ''],
            ['441100', 'Pendapatan Anak', 'pendapatan', '111000'], // beda kategori
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak konsisten', $e->getMessage());
        }
    }

    public function test_invalid_code_characters_throws(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111 100', 'Kas', 'aset', ''], // spasi tidak diperbolehkan
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('invalid', $e->getMessage());
        }
    }

    public function test_multiple_errors_accumulate(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['', 'Kas', 'aset', ''],              // Kode kosong
            ['111100', '', 'aset', ''],           // Nama kosong
            ['111200', 'Piutang', 'xxx', ''],     // Kategori invalid
        ]);

        try {
            $this->service->parseAndValidate($path);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('Kode\' kosong', $msg);
            $this->assertStringContainsString('Nama Akun\' kosong', $msg);
            $this->assertStringContainsString('Kategori \'xxx\' invalid', $msg);
        }
    }

    public function test_seed_from_excel_inserts_accounts_atomically(): void
    {
        $company = Company::factory()->create();

        $path = $this->makeXlsx([
            $this->header(),
            ['111100', 'Kas', 'aset', ''],
            ['441100', 'Pendapatan Sewa', 'pendapatan', ''],
            ['551100', 'Beban BBM', 'beban', ''],
        ]);

        $inserted = $this->service->seedFromExcel($company, $path);

        $this->assertSame(3, $inserted);

        // BelongsToCompany scope tidak aktif di test tanpa Filament::setTenant()
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        $this->assertCount(3, $accounts);
        $this->assertSame('debit', $accounts->firstWhere('code', '111100')->normal_balance);
        $this->assertSame('kredit', $accounts->firstWhere('code', '441100')->normal_balance);
        $this->assertSame('debit', $accounts->firstWhere('code', '551100')->normal_balance);
    }

    public function test_generated_template_round_trips_through_parser(): void
    {
        // Generate template pakai exporter yang sama dengan production
        $exporter = new CoaTemplateExporter();
        $reflection = new \ReflectionClass($exporter);
        $method = $reflection->getMethod('build');
        $method->setAccessible(true);
        $spreadsheet = $method->invoke($exporter);

        $path = $this->tmpDir . '/round-trip.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        // Template contoh HARUS parse-able (5 baris demo semuanya valid)
        $rows = $this->service->parseAndValidate($path);

        $this->assertGreaterThanOrEqual(5, count($rows));
        $this->assertSame('111000', $rows[0]['code']);
    }

    public function test_empty_rows_between_data_are_skipped(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111100', 'Kas', 'aset', ''],
            ['', '', '', ''],  // baris kosong di tengah
            ['441100', 'Pendapatan', 'pendapatan', ''],
        ]);

        $rows = $this->service->parseAndValidate($path);
        $this->assertCount(2, $rows);
    }

    public function test_case_insensitive_category_accepted(): void
    {
        $path = $this->makeXlsx([
            $this->header(),
            ['111100', 'Kas', 'ASET', ''],       // uppercase
            ['441100', 'Pendapatan', 'Pendapatan', ''], // capitalize
        ]);

        $rows = $this->service->parseAndValidate($path);
        $this->assertSame('aset', $rows[0]['category']);
        $this->assertSame('pendapatan', $rows[1]['category']);
    }
}
