<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Widgets\ClientPaymentHistory;
use App\Filament\Resources\Clients\Widgets\ClientPaymentSummary;
use App\Filament\Resources\Invoices\Pages\ReceivableReport;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PaymentService;
use App\Services\Accounting\ReceivableReportService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Keterlambatan bayar, laporan & export piutang, riwayat pembayaran klien.
 * "Hari ini" dibekukan 16 Nov 2026.
 */
class ReceivablesTest extends TestCase
{
    private Company $company;
    private User $owner;
    private Client $client;
    private Account $kas;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');

        $this->company = $this->createTenant(['name' => 'PT Piutang']);
        $this->owner   = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        $this->client  = $this->createClient($this->company, ['code' => 'KL-01', 'name' => 'CV Lambat']);
        $this->kas     = $this->postableAccount($this->company, '111100');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $date, string $due, int $amount = 1_000_000, ?Client $client = null): Invoice
    {
        return app(InvoiceService::class)->issue($this->makeDraftInvoice($this->company, [
            'client_id' => ($client ?? $this->client)->id, 'amount' => $amount,
            'invoice_date' => $date, 'due_date' => $due,
        ]));
    }

    private function pay(Invoice $invoice, int $amount, string $date): void
    {
        app(PaymentService::class)->pay($invoice->fresh(), $this->kas, $amount, Carbon::parse($date));
    }

    // ── Keterlambatan ───────────────────────────────────────────────────

    public function test_keterlambatan_dihitung_dari_jatuh_tempo(): void
    {
        $belum   = $this->invoice('2026-11-01', '2026-11-20');
        $telat   = $this->invoice('2026-09-01', '2026-10-01');
        $lunasTelat = $this->invoice('2026-08-01', '2026-08-31');
        $this->pay($lunasTelat, 1_000_000, '2026-09-10');
        $lunasTepat = $this->invoice('2026-08-01', '2026-08-31');
        $this->pay($lunasTepat, 1_000_000, '2026-08-30');

        $this->assertSame(0, $belum->fresh()->daysLate());
        $this->assertSame('Jatuh tempo 4 hari lagi', $belum->fresh()->timelinessLabel());

        $this->assertSame(46, $telat->fresh()->daysLate(), '1 Okt → 16 Nov');
        $this->assertSame('Telat 46 hari', $telat->fresh()->timelinessLabel());
        $this->assertSame('danger', $telat->fresh()->timelinessColor());

        $this->assertSame('Lunas, telat 10 hari', $lunasTelat->fresh()->timelinessLabel(), 'Pelunasan 10 Sep vs jatuh tempo 31 Agu');
        $this->assertSame('Lunas tepat waktu', $lunasTepat->fresh()->timelinessLabel());
    }

    public function test_filter_keterlambatan_portabel_per_kelompok(): void
    {
        $this->invoice('2026-11-01', '2026-11-20');                   // belum
        $a = $this->invoice('2026-10-01', '2026-11-01');              // telat 15
        $b = $this->invoice('2026-08-01', '2026-09-01');              // telat 76
        $c = $this->invoice('2026-05-01', '2026-06-01');              // telat 168
        $lunasTelat = $this->invoice('2026-08-01', '2026-08-31');
        $this->pay($lunasTelat, 1_000_000, '2026-09-10');

        $ids = fn (string $v) => InvoicesTable::applyLatenessFilter(Invoice::query(), $v)->pluck('id')->all();

        $this->assertSame([$a->id], $ids('1-30'));
        $this->assertSame([], $ids('31-60'));
        $this->assertSame([$b->id], $ids('61-90'));
        $this->assertSame([$c->id], $ids('>90'));
        $this->assertCount(1, $ids('belum'));
        $this->assertSame([$lunasTelat->id], $ids('lunas_telat'));
    }

    // ── Laporan piutang ─────────────────────────────────────────────────

    public function test_laporan_posisi_per_tanggal_dan_rata_rata_telat(): void
    {
        $inv = $this->invoice('2026-09-01', '2026-10-01', 3_000_000);
        $this->pay($inv, 1_000_000, '2026-10-15');
        $this->pay($inv, 500_000, '2026-11-10');                        // setelah tanggal laporan 31 Okt
        $lunas = $this->invoice('2026-07-01', '2026-07-31', 2_000_000);
        $this->pay($lunas, 2_000_000, '2026-08-20');                    // telat 20 hari

        $svc   = app(ReceivableReportService::class);
        $aging = $svc->agingByClient($this->company->id, Carbon::create(2026, 10, 31))->first();

        $this->assertSame(2_000_000.0, $aging['total_sisa'], 'Per 31 Okt: 3jt − 1jt; bayaran 10 Nov belum dihitung');
        $this->assertSame(2_000_000.0, $aging['bucket_1-30'], 'Telat 30 hari per 31 Okt');
        $this->assertSame(20.0, $aging['rata_telat']);
        $this->assertSame(1, $aging['lunas_telat']);

        $out = $svc->outstandingInvoices($this->company->id, Carbon::today());
        $this->assertSame(1_500_000.0, $out->first()->sisa);
        $this->assertSame(46, $out->first()->days_late);

        $payments = $svc->payments($this->company->id, Carbon::create(2026, 10, 1), Carbon::today());
        $this->assertSame([40, 14], $payments->pluck('days_late')->all(), 'Terbaru dulu: 10 Nov (40 hari), 15 Okt (14 hari)');
    }

    public function test_halaman_laporan_tampil(): void
    {
        $this->invoice('2026-09-01', '2026-10-01');
        Filament::setCurrentPanel('admin');

        Livewire::test(ReceivableReport::class)
            ->assertSee('Umur Piutang per Klien')
            ->assertSee('CV Lambat')
            ->assertSee('Telat 46 hari');
    }

    // ── Export ──────────────────────────────────────────────────────────

    public function test_export_excel_tiga_sheet(): void
    {
        $inv = $this->invoice('2026-09-01', '2026-10-01');
        $this->pay($inv, 400_000, '2026-10-20');

        $response = $this->get(route('export.piutang', ['tenant' => $this->company->slug, 'dari' => '2026-01-01', 'sampai' => '2026-11-16']));
        $response->assertOk();

        $ss = IOFactory::load($response->getFile()->getPathname());
        $this->assertSame(['Umur Piutang', 'Invoice Belum Lunas', 'Riwayat Pembayaran'], $ss->getSheetNames());
        $this->assertSame('CV Lambat', $ss->getSheet(0)->getCell('B5')->getValue());
        $this->assertSame(600_000, (int) $ss->getSheet(1)->getCell('I5')->getValue(), 'Sisa');
        $this->assertSame(46, (int) $ss->getSheet(1)->getCell('J5')->getValue(), 'Telat (hari)');
        $this->assertSame('Telat 19 hari', $ss->getSheet(2)->getCell('I5')->getValue());
    }

    public function test_export_menolak_klien_pt_lain_dan_bukan_anggota(): void
    {
        $other = $this->createTenant();
        $foreignClient = Client::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'code' => 'X', 'name' => 'Klien PT Lain', 'is_active' => true,
        ]);
        $url = fn (array $extra = []) => route('export.piutang', ['tenant' => $this->company->slug, 'dari' => '2026-01-01', 'sampai' => '2026-11-16'] + $extra);

        $this->get($url(['client_id' => $foreignClient->id]))->assertNotFound();

        $this->actingAs($this->createTenantUser($other))->get($url())->assertForbidden();
    }

    // ── Riwayat pembayaran klien ────────────────────────────────────────

    public function test_riwayat_pembayaran_klien_hanya_milik_klien_itu(): void
    {
        $inv = $this->invoice('2026-09-01', '2026-10-01');
        $this->pay($inv, 300_000, '2026-10-20');
        $lain = $this->createClient($this->company, ['name' => 'Klien Lain']);
        $this->pay($this->invoice('2026-09-01', '2026-10-01', client: $lain), 100_000, '2026-10-02');

        $this->assertCount(1, $this->client->payments);
        Filament::setCurrentPanel('admin');

        Livewire::test(ClientPaymentHistory::class, ['record' => $this->client])
            ->assertCanSeeTableRecords($this->client->payments)
            ->assertCanNotSeeTableRecords($lain->payments)
            ->assertSee('Telat 19 hari')
            ->searchTable($this->client->payments->first()->payment_number)   // dulu crash di hasManyThrough
            ->filterTable('ketepatan', 'telat')
            ->assertCanSeeTableRecords($this->client->payments)
            ->sortTable('amount', 'desc')                                   // kolom `amount` ada di 2 tabel
            ->assertCanSeeTableRecords($this->client->payments)
            ->filterTable('ketepatan', 'tepat')
            ->assertCanNotSeeTableRecords($this->client->payments);

        Livewire::test(ClientPaymentSummary::class, ['record' => $this->client])
            ->assertSee('Rp 300.000')        // sudah dibayar
            ->assertSee('Rp 700.000');       // sisa
    }
}
