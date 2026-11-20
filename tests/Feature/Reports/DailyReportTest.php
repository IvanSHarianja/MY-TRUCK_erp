<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\Reports\DailyReport;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\DailyCashReportService;
use App\Services\Accounting\DebtService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PaymentService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Laporan Harian Keuangan: posisi kas per rekening + aktivitas satu tanggal. */
class DailyReportTest extends TestCase
{
    private Company $company;
    private User $owner;
    private Account $bca;
    private Account $mandiri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant();
        $this->owner = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        Filament::setCurrentPanel('admin');
        $mk = fn (string $code, string $name) => Account::create([
            'company_id' => $this->company->id, 'code' => $code, 'parent_code' => '111100',
            'name' => $name, 'category' => 'aset', 'normal_balance' => 'debit', 'role' => 'cash', 'is_active' => true,
        ]);
        $this->bca = $mk('111100-01', 'Bank BCA');
        $this->mandiri = $mk('111100-02', 'Bank Mandiri');
    }

    private function postJournal(array $lines, Carbon $date): void
    {
        app(JournalService::class)->post($this->makeJournalEntry($this->company, $lines, [], $date));
    }

    public function test_posisi_kas_per_rekening_dan_aktivitas_hari_itu(): void
    {
        $kemarin = today()->subDay();
        $modal = $this->postableAccount($this->company, '331100');
        $beban = $this->postableAccount($this->company, '552500');

        // Kemarin: modal masuk BCA 10 jt (jadi saldo awal hari ini).
        $this->postJournal([
            ['account_id' => $this->bca->id, 'debit' => 10_000_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0, 'kredit' => 10_000_000],
        ], $kemarin);
        // Hari ini: pindah 3 jt BCA → Mandiri, bayar beban 500 rb dari Mandiri.
        $this->postJournal([
            ['account_id' => $this->mandiri->id, 'debit' => 3_000_000, 'kredit' => 0],
            ['account_id' => $this->bca->id, 'debit' => 0, 'kredit' => 3_000_000],
        ], today());
        $this->postJournal([
            ['account_id' => $beban->id, 'debit' => 500_000, 'kredit' => 0],
            ['account_id' => $this->mandiri->id, 'debit' => 0, 'kredit' => 500_000],
        ], today());

        // Invoice terbit + dibayar sebagian hari ini.
        $invoice = app(InvoiceService::class)->issue($this->makeDraftInvoice($this->company, ['amount' => 2_000_000]));
        app(PaymentService::class)->pay($invoice, $this->bca, 1_200_000, today());

        $r = app(DailyCashReportService::class)->build($this->company->id, today());

        $bca = collect($r['rekening'])->firstWhere('name', 'Bank BCA');
        $mdr = collect($r['rekening'])->firstWhere('name', 'Bank Mandiri');
        $this->assertEquals([10_000_000, 1_200_000, 3_000_000, 8_200_000], [$bca['opening'], $bca['in'], $bca['out'], $bca['closing']]);
        $this->assertEquals([0, 3_000_000, 500_000, 2_500_000], [$mdr['opening'], $mdr['in'], $mdr['out'], $mdr['closing']]);
        $this->assertEquals(10_700_000, $r['totals']['closing'], '10 jt − 500 rb + 1,2 jt');

        $this->assertContains('Pindah dana antar rekening', array_column($r['mutasi'], 'lawan'));
        $this->assertCount(1, $r['invoices']);
        $this->assertEquals(1_200_000, $r['payments']->sum('amount'));

        // Kemarin: hanya modal.
        $y = app(DailyCashReportService::class)->build($this->company->id, $kemarin);
        $this->assertEquals(10_000_000, $y['totals']['closing']);
        $this->assertCount(0, $y['invoices']);
    }

    public function test_cicilan_dibayar_dan_jatuh_tempo_hari_ini(): void
    {
        $svc = app(DebtService::class);
        $debt = $svc->create($this->company, $this->owner, [
            'jenis' => 'bank', 'kreditur' => 'BRI', 'payable_account_id' => $this->postableAccount($this->company, '222110')->id,
            'pokok' => 2_000_000, 'tenor_bulan' => 2, 'tanggal_mulai' => today()->subMonth()->toDateString(),
            'jatuh_tempo_pertama' => today()->toDateString(), 'disbursement_account_id' => $this->bca->id,
        ]);
        $r = app(DailyCashReportService::class)->build($this->company->id, today());
        $this->assertCount(1, $r['due_installments']);

        $svc->pay($debt->installments->first(), $this->owner, ['paid_date' => today()->toDateString(), 'cash_account_id' => $this->bca->id]);
        $r = app(DailyCashReportService::class)->build($this->company->id, today());
        $this->assertCount(1, $r['installments']);
        $this->assertCount(0, $r['due_installments']);
    }

    public function test_halaman_dan_pdf_terbuka(): void
    {
        Livewire::test(DailyReport::class)
            ->assertOk()
            ->assertSee('Laporan Harian Keuangan')
            ->callAction('prev')
            ->assertSet('data.tanggal', today()->subDay()->toDateString());

        $this->get(route('pdf.daily-report', ['tenant' => $this->company->slug, 'tanggal' => today()->toDateString()]))
            ->assertOk();
    }
}
