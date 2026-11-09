<?php

namespace Tests\Feature\Export;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PaymentService;
use App\Services\Export\SopExportException;
use App\Services\Export\SopFinanceExporter;
use App\Support\RoleMatrix;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Backup Excel format SOP Finance.
 *
 * buildPayload() diuji langsung (tanpa memuat template 6 MB). Periode uji:
 * 1â€“31 Maret 2026.
 */
class SopFinanceExporterTest extends TestCase
{
    private SopFinanceExporter $exporter;
    private Company $company;
    private Account $kas;
    private Account $beban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = app(SopFinanceExporter::class);
        $this->company  = $this->createTenant(['name' => 'PT Uji Ekspor']);
        $this->actingAsTenant($this->createTenantUser($this->company), $this->company);
        $this->kas   = $this->postableAccount($this->company, '111100');
        $this->beban = $this->postableAccount($this->company, '552500');
    }

    private function payload(): array
    {
        return $this->exporter->buildPayload($this->company, Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 31));
    }

    private function postedBeban(int $amount, CarbonInterface $date, array $overrides = []): \App\Models\JournalEntry
    {
        $entry = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->beban->id, 'debit' => $amount, 'kredit' => 0],
            ['account_id' => $this->kas->id, 'debit' => 0, 'kredit' => $amount],
        ], $overrides, $date);

        return app(JournalService::class)->post($entry);
    }

    /** Baris JURNAL sebagai list [kolom => nilai] per baris, urut. */
    private function jurnalRows(array $payload): array
    {
        $rows = [];
        foreach ($payload['cells']['JURNAL'] as $coord => $value) {
            preg_match('/^([A-Z]+)(\d+)$/', $coord, $m);
            $rows[(int) $m[2]][$m[1]] = $value;
        }
        ksort($rows);

        return array_values($rows);
    }

    /** Cari baris COA (B..J) untuk kode akun tertentu. */
    private function coaRow(array $payload, string $code): array
    {
        foreach ($payload['cells']['COA'] as $coord => $value) {
            if (str_starts_with($coord, 'B') && (string) $value === $code) {
                $row = substr($coord, 1);
                return [
                    'C' => $payload['cells']['COA']["C{$row}"],
                    'D' => $payload['cells']['COA']["D{$row}"],
                    'E' => $payload['cells']['COA']["E{$row}"],
                    'F' => $payload['cells']['COA']["F{$row}"],
                ];
            }
        }
        $this->fail("Akun {$code} tidak ada di payload COA");
    }

    // â”€â”€ JURNAL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_jurnal_hanya_berisi_jurnal_posted_dalam_periode(): void
    {
        $this->postedBeban(100_000, Carbon::create(2026, 2, 28));                        // sebelum periode
        $this->postedBeban(200_000, Carbon::create(2026, 3, 1), ['document_number' => 'BKK-01']);
        $this->postedBeban(300_000, Carbon::create(2026, 3, 31));                        // batas akhir (ikut)
        $this->postedBeban(400_000, Carbon::create(2026, 4, 1));                         // sesudah periode
        $this->makeJournalEntry($this->company, [                                        // draft
            ['account_id' => $this->beban->id, 'debit' => 500_000, 'kredit' => 0],
            ['account_id' => $this->kas->id, 'debit' => 0, 'kredit' => 500_000],
        ], [], Carbon::create(2026, 3, 15));
        $void = $this->postedBeban(600_000, Carbon::create(2026, 3, 20));
        app(JournalService::class)->void($void, 'salah input');

        $rows = $this->jurnalRows($this->payload());

        $this->assertCount(4, $rows, 'Hanya 2 jurnal Ã— 2 baris (200rb & 300rb) yang boleh masuk');
        $this->assertSame([200_000.0, 300_000.0], [$rows[0]['K'], $rows[2]['K']]);

        $this->assertInstanceOf(CarbonInterface::class, $rows[0]['B']);
        $this->assertSame('2026-03-01', $rows[0]['B']->toDateString());
        $this->assertSame('BKK-01', $rows[0]['C'], 'REF = No. Bukti bila ada');
        $this->assertStringStartsWith('J2603-', $rows[2]['C'], 'REF = nomor jurnal bila No. Bukti kosong');
        $this->assertSame($this->beban->name, $rows[0]['I']);
        $this->assertNull($rows[0]['L']);
        $this->assertSame(200_000.0, $rows[1]['L']);
        $this->assertSame('Operasi', $rows[1]['M'], 'Baris Kas wajib punya kategori arus kas');
        $this->assertNull($rows[0]['M'], 'Baris non-kas tidak diisi arus kas');
    }

    public function test_transfer_antar_kas_diberi_kategori_transfer(): void
    {
        $kas2 = $this->postableAccount($this->company, '111110'); // Kas Kecil Lapangan

        $entry = $this->makeJournalEntry($this->company, [
            ['account_id' => $kas2->id, 'debit' => 50_000, 'kredit' => 0],
            ['account_id' => $this->kas->id, 'debit' => 0, 'kredit' => 50_000],
        ], [], Carbon::create(2026, 3, 5));
        app(JournalService::class)->post($entry);

        $rows = $this->jurnalRows($this->payload());

        $this->assertSame(['Transfer Antar Kas', 'Transfer Antar Kas'], array_column($rows, 'M'));
    }

    // â”€â”€ COA & saldo awal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_saldo_awal_coa_meringkas_jurnal_sebelum_periode_dan_jurnal_saldo_awal(): void
    {
        $modal = $this->postableAccount($this->company, '331100');

        // Jurnal saldo awal di DALAM periode â†’ masuk saldo awal COA, bukan JURNAL.
        $saldoAwal = $this->makeJournalEntry($this->company, [
            ['account_id' => $this->kas->id, 'debit' => 10_000_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0, 'kredit' => 10_000_000],
        ], ['document_type' => 'saldo_awal'], Carbon::create(2026, 3, 2));
        app(JournalService::class)->post($saldoAwal);

        $this->postedBeban(1_000_000, Carbon::create(2026, 1, 10));   // sebelum periode
        $this->postedBeban(250_000, Carbon::create(2026, 3, 10));     // dalam periode

        $payload = $this->payload();

        $kas = $this->coaRow($payload, $this->kas->code);
        $this->assertSame(9_000_000.0, $kas['E'], 'Kas: 10jt saldo awal âˆ’ 1jt beban Januari');
        $this->assertNull($kas['F']);
        $this->assertSame('Kas', $kas['D']);

        $this->assertSame(10_000_000.0, $this->coaRow($payload, $modal->code)['F']);
        $this->assertSame(1_000_000.0, $this->coaRow($payload, $this->beban->code)['E'],
            'Beban sebelum periode ikut saldo awal â€” sheet NRL memisahkan L/R berdasarkan mutasi periode');

        $this->assertCount(2, $this->jurnalRows($payload), 'Hanya jurnal 250rb Maret; saldo_awal tidak masuk JURNAL');
    }

    public function test_nama_akun_ganda_dibedakan_dengan_kode(): void
    {
        $twin = Account::withoutGlobalScopes()->create([
            'company_id'     => $this->company->id,
            'code'           => '552599',
            'parent_code'    => $this->beban->parent_code,
            'name'           => $this->beban->name,
            'category'       => 'beban',
            'sub_category'   => 'beban_operasional',
            'normal_balance' => 'debit',
            'is_active'      => true,
        ]);

        $payload = $this->payload();

        $this->assertSame("{$twin->name} (552599)", $this->coaRow($payload, '552599')['C']);
        $this->assertSame("{$this->beban->name} ({$this->beban->code})", $this->coaRow($payload, $this->beban->code)['C']);
        $this->assertNotEmpty($payload['warnings']);
    }

    // â”€â”€ Tagihan, Pembayaran, buku pembantu â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_tagihan_pembayaran_dan_buku_pembantu_piutang(): void
    {
        $client = $this->createClient($this->company, ['code' => 'KL-07', 'name' => 'CV Klien Uji']);
        $invoices = app(InvoiceService::class);
        $payments = app(PaymentService::class);

        // Invoice lama (Feb) dibayar sebagian di Feb & Mar â†’ ikut karena masih bersisa per 1 Mar.
        $old = $invoices->issue($this->makeDraftInvoice($this->company, [
            'client_id' => $client->id, 'amount' => 1_000_000,
            'invoice_date' => '2026-02-10', 'due_date' => '2026-03-12',
        ]));
        $payments->pay($old, $this->kas, 400_000, Carbon::create(2026, 2, 20));
        $payments->pay($old, $this->kas, 600_000, Carbon::create(2026, 3, 5));

        // Invoice lama lunas sebelum periode â†’ tidak ikut.
        $lunas = $invoices->issue($this->makeDraftInvoice($this->company, [
            'client_id' => $client->id, 'amount' => 500_000,
            'invoice_date' => '2026-02-11', 'due_date' => '2026-03-13',
        ]));
        $payments->pay($lunas, $this->kas, 500_000, Carbon::create(2026, 2, 25));

        // Invoice baru dalam periode.
        $new = $invoices->issue($this->makeDraftInvoice($this->company, [
            'client_id' => $client->id, 'amount' => 2_000_000,
            'invoice_date' => '2026-03-15', 'due_date' => '2026-04-14',
        ]));

        $payload = $this->payload();
        $tagihan = $payload['cells']['Tagihan'];

        $this->assertSame(2, $payload['counts']['tagihan']);
        $this->assertSame($old->invoice_number, $tagihan['C12']);
        $this->assertSame([10, 2, 2026], [$tagihan['E12'], $tagihan['F12'], $tagihan['G12']]);
        $this->assertSame('Manual', $tagihan['H12']);
        $this->assertSame('KL-07', $tagihan['J12']);
        $this->assertSame('RENT', $tagihan['K12']);
        $this->assertSame(1_000_000.0, $tagihan['L12']);
        $this->assertSame(1_000_000.0, $tagihan['V12'], 'Dibayar = semua pembayaran s.d. SAMPAI, termasuk sebelum periode');
        $this->assertSame(30, $tagihan['T12']);
        $this->assertSame('Sudah (periode sebelumnya)', $tagihan['AB12']);

        $this->assertSame($new->invoice_number, $tagihan['C13']);
        $this->assertSame(0.0, $tagihan['V13']);
        $this->assertArrayNotHasKey('AB13', $tagihan, 'Invoice dalam periode: biarkan rumus Status Jurnal');

        $bayar = $payload['cells']['Pembayaran'];
        $this->assertSame(1, $payload['counts']['pembayaran']);
        $this->assertSame($old->invoice_number, $bayar['H12']);
        $this->assertSame(600_000.0, $bayar['M12'], 'Sisa sebelum = 1jt âˆ’ 400rb (Februari)');
        $this->assertSame(600_000.0, $bayar['N12']);
        $this->assertSame($this->kas->name, $bayar['O12']);
        $this->assertSame('Valid', $bayar['R12']);

        // Master Klien: kode MY-TRUCK, saldo awal piutang per 1 Mar = 600rb (invoice lama).
        $klienRow = array_search('KL-07', $payload['cells']['Master Klien'], true);
        $row = substr($klienRow, 1);
        $this->assertSame(600_000.0, $payload['cells']['Master Klien']["J{$row}"]);

        // JURNAL: baris Piutang punya buku pembantu "KODE | Nama".
        $piutangRows = array_filter($this->jurnalRows($payload), fn ($r) => $r['G'] !== null);
        $this->assertNotEmpty($piutangRows);
        foreach ($piutangRows as $r) {
            $this->assertSame('KL-07 | CV Klien Uji', $r['G']);
        }
    }

    public function test_invoice_void_dalam_periode_ditandai_void(): void
    {
        $invoice = app(InvoiceService::class)->issue($this->makeDraftInvoice($this->company, [
            'amount' => 750_000, 'invoice_date' => '2026-03-03', 'due_date' => '2026-04-02',
        ]));
        app(JournalService::class)->void($invoice->journalEntry, 'batal');

        $tagihan = $this->payload()['cells']['Tagihan'];

        $this->assertSame('Void', $tagihan['X12']);
        $this->assertSame('Void', $tagihan['AB12']);
    }

    // â”€â”€ Kapasitas & keamanan penulisan â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_melebihi_kapasitas_template_ditolak_sebelum_memuat_file(): void
    {
        $now = now();
        DB::table('clients')->insert(collect(range(1, 501))->map(fn ($i) => [
            'company_id' => $this->company->id, 'code' => sprintf('C%04d', $i), 'name' => "Klien {$i}",
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $this->expectException(SopExportException::class);
        $this->expectExceptionMessageMatches('/Master Klien: 501 baris.*500/');

        $this->payload();
    }

    public function test_teks_diawali_sama_dengan_tidak_menjadi_rumus(): void
    {
        $this->createClient($this->company, ['code' => 'X1', 'name' => '=HYPERLINK("http://evil","klik")']);

        $payload     = $this->payload();
        $spreadsheet = new Spreadsheet();
        foreach (array_keys($payload['cells']) as $i => $name) {
            ($i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet())->setTitle($name);
        }

        $this->exporter->write($spreadsheet, $payload);

        $sheet = $spreadsheet->getSheetByName('Master Klien');
        $row   = null;
        foreach ($sheet->getColumnIterator('C')->current()->getCellIterator(12) as $cell) {
            if ($cell->getValue() === 'X1') {
                $row = $cell->getRow();
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell("D{$row}")->getDataType());

        // Tanggal ditulis sebagai serial Excel (ISNUMBER di rumus SOP).
        $this->assertSame(DataType::TYPE_NUMERIC, $spreadsheet->getSheetByName('NRL')->getCell('E3')->getDataType());
    }

    // â”€â”€ Akses â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_hanya_owner_dan_admin_yang_boleh_unduh_backup(): void
    {
        $this->assertTrue(RoleMatrix::has(Role::Owner, Permission::DataExport));
        $this->assertTrue(RoleMatrix::has(Role::Admin, Permission::DataExport));
        $this->assertFalse(RoleMatrix::has(Role::Accountant, Permission::DataExport));
        $this->assertFalse(RoleMatrix::has(Role::Viewer, Permission::DataExport));
    }

    public function test_route_menolak_viewer_dan_pengguna_pt_lain(): void
    {
        $url = route('export.sop', ['file' => 'finance', 'tenant' => $this->company->slug, 'dari' => '2026-03-01', 'sampai' => '2026-03-31']);

        $viewer = $this->createTenantUser($this->company, [], 'viewer');
        $this->actingAs($viewer)->get($url)->assertForbidden();

        $other = $this->createTenant();
        $outsider = $this->createTenantUser($other);
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }

    public function test_route_owner_mengunduh_file_dan_mencatat_aktivitas(): void
    {
        $this->app->instance(SopFinanceExporter::class, new class(app(\App\Services\Export\SopMasterData::class)) extends SopFinanceExporter {
            public function export(Company $company, CarbonInterface $dari, CarbonInterface $sampai, string $outputPath, ?string $exportedBy = null): array
            {
                file_put_contents($outputPath, 'xlsx');
                return ['counts' => ['jurnal' => 0], 'warnings' => []];
            }
        });

        $owner = $this->createTenantUser($this->company);
        $response = $this->actingAs($owner)->get(route('export.sop', ['file' => 'finance', 
            'tenant' => $this->company->slug, 'dari' => '2026-03-01', 'sampai' => '2026-03-31',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('SOP Finance - pt-uji-ekspor - 20260301 sd 20260331.xlsx',
            $response->headers->get('content-disposition'));

        $this->assertDatabaseHas('activity_log', [
            'causer_id'    => $owner->id,
            'subject_type' => Company::class,
            'subject_id'   => $this->company->id,
            'description'  => 'Unduh backup Excel (SOP Finance)',
        ]);
    }

    public function test_halaman_backup_hanya_untuk_owner_admin_dan_tombol_mengarah_ke_unduhan(): void
    {
        \Filament\Facades\Filament::setCurrentPanel('admin');

        $viewer = $this->createTenantUser($this->company, [], 'viewer');
        $this->actingAsTenant($viewer, $this->company);
        $this->assertFalse(\App\Filament\Pages\BackupExcel::canAccess());

        $admin = $this->createTenantUser($this->company, [], 'admin');
        $this->actingAsTenant($admin, $this->company);
        $this->assertTrue(\App\Filament\Pages\BackupExcel::canAccess());

        \Livewire\Livewire::test(\App\Filament\Pages\BackupExcel::class)
            ->assertSee('Unduh Backup Excel')
            ->set('data.dari', '2026-03-01')
            ->set('data.sampai', '2026-03-31')
            ->call('download')
            ->assertRedirect(route('export.sop', ['file' => 'finance', 
                'tenant' => $this->company->slug, 'dari' => '2026-03-01', 'sampai' => '2026-03-31',
            ]));
    }

    public function test_route_menolak_periode_terbalik(): void
    {
        $owner = $this->createTenantUser($this->company);

        $this->actingAs($owner)
            ->get(route('export.sop', ['file' => 'finance', 'tenant' => $this->company->slug, 'dari' => '2026-03-31', 'sampai' => '2026-03-01']))
            ->assertSessionHasErrors('sampai');
    }
}
