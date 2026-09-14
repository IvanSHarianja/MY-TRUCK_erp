<?php

namespace Tests\Feature\Accounting;

use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression: nomor dokumen mentok setelah melewati jumlah digit padding.
 *
 * Sebelum fix, nomor terakhir diambil via ORDER BY kolom DESC (urutan string)
 * → "J2609-999" dianggap lebih besar dari "J2609-1000" → generator mengulang
 * "J2609-1000" → unique violation → createEntryWithLines gagal setelah 5 retry.
 * Artinya maksimal 1.000 jurnal per PT per bulan (termasuk auto-journal BBK/DEPUSE).
 */
class DocumentSequenceTest extends TestCase
{
    private function balancedLines($company): array
    {
        return [
            ['account_id' => $this->postableAccount($company, '551100')->id, 'debit' => 100_000, 'kredit' => 0],
            ['account_id' => $this->postableAccount($company, '111100')->id, 'debit' => 0, 'kredit' => 100_000],
        ];
    }

    private function prefix(string $letters): string
    {
        return $letters . Carbon::today()->format('ym') . '-';
    }

    public function test_nomor_jurnal_pertama_tetap_3_digit(): void
    {
        $company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($company), $company);

        $number = app(JournalService::class)->generateEntryNumber($company, Carbon::today());

        $this->assertSame($this->prefix('J') . '001', $number);
    }

    public function test_nomor_jurnal_lanjut_ke_1000_setelah_999(): void
    {
        $company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($company), $company);

        $this->makeJournalEntry($company, $this->balancedLines($company), ['entry_number' => $this->prefix('J') . '998']);
        $this->makeJournalEntry($company, $this->balancedLines($company), ['entry_number' => $this->prefix('J') . '999']);

        $number = app(JournalService::class)->generateEntryNumber($company, Carbon::today());

        $this->assertSame($this->prefix('J') . '1000', $number);
    }

    public function test_jurnal_ke_1001_berhasil_dibuat(): void
    {
        $company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($company), $company);

        $this->makeJournalEntry($company, $this->balancedLines($company), ['entry_number' => $this->prefix('J') . '999']);
        $this->makeJournalEntry($company, $this->balancedLines($company), ['entry_number' => $this->prefix('J') . '1000']);

        // Jalur produksi: createEntryWithLines memakai generateEntryNumber.
        // Sebelum fix: "J..-1000" diulang → UniqueConstraintViolationException.
        $entry = $this->makeJournalEntry($company, $this->balancedLines($company));

        $this->assertSame($this->prefix('J') . '1001', $entry->entry_number);
    }

    public function test_nomor_jurnal_tidak_terpengaruh_company_lain(): void
    {
        $companyA = $this->createTenant();
        $companyB = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($companyA), $companyA);

        $this->makeJournalEntry($companyA, $this->balancedLines($companyA), ['entry_number' => $this->prefix('J') . '1000']);

        $number = app(JournalService::class)->generateEntryNumber($companyB, Carbon::today());

        $this->assertSame($this->prefix('J') . '001', $number);
    }

    public function test_nomor_invoice_lanjut_setelah_9999(): void
    {
        // Kelas bug yang sama di generator 4 digit (INV/PAY/PB/PJ/kontrak/proyek).
        $company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($company), $company);

        $this->makeDraftInvoice($company, ['invoice_number' => $this->prefix('INV') . '9999']);
        $this->makeDraftInvoice($company, ['invoice_number' => $this->prefix('INV') . '10000']);

        $number = app(InvoiceService::class)->generateInvoiceNumber($company, Carbon::today());

        $this->assertSame($this->prefix('INV') . '10001', $number);
    }
}
