<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Services\Accounting\CashFlowLakService;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Test CashFlowLakService — format LAK operasional (mirror Excel).
 *
 * Skenario minimal (ponytail: 1 test yang cover happy path + tenant isolation):
 *  - Buat tenant, seed COA + BU
 *  - Post 4 jurnal: setoran modal, terima piutang RENT, bayar BBM ARMD, bayar gaji kantor
 *  - Panggil getReport()
 *  - Assert: saldo awal 0, penerimaan modal + piutang RENT match, pengeluaran BBM EXP & gaji kantor match
 *  - Saldo akhir = penerimaan - pengeluaran
 */
class CashFlowLakServiceTest extends TestCase
{
    public function test_lak_report_mirror_excel_format(): void
    {
        $company = $this->createTenant();
        $user    = $this->createTenantUser($company);
        $this->actingAsTenant($user, $company);

        $today = Carbon::create(2026, 6, 15);

        // Ambil akun by role
        $kas       = $this->postableAccount($company, '111100');
        $piutang   = $this->postableAccount($company, '111200');
        $modal     = $this->postableAccount($company, '331100');
        $cogsBbm   = $this->postableAccount($company, '551100');
        $opexGaji  = $this->postableAccount($company, '552200');
        $buRent    = $this->businessUnit($company, 'RENT');
        $buArmd    = $this->businessUnit($company, 'ARMD');

        $svc = app(JournalService::class);

        // === Jurnal 1: Setoran modal Rp 100jt (kas masuk, MODAL) ===
        $j1 = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,   'debit' => 100_000_000, 'kredit' => 0],
            ['account_id' => $modal->id, 'debit' => 0,           'kredit' => 100_000_000],
        ], overrides: ['document_type' => 'manual'], date: $today);
        $svc->post($j1);

        // === Jurnal 2: Terima pembayaran piutang RENT Rp 20jt ===
        // (kas masuk lawan piutang, BU=RENT → baris "PIUTANG ALAT BERAT")
        $j2 = $this->makeJournalEntry($company, [
            ['account_id' => $kas->id,     'debit' => 20_000_000, 'kredit' => 0],
            ['account_id' => $piutang->id, 'debit' => 0,          'kredit' => 20_000_000],
        ], overrides: ['document_type' => 'manual', 'business_unit_id' => $buRent->id], date: $today);
        $svc->post($j2);

        // === Jurnal 3: Bayar BBM Dump Truck Rp 5jt (BU=ARMD) ===
        $j3 = $this->makeJournalEntry($company, [
            ['account_id' => $cogsBbm->id, 'debit' => 5_000_000, 'kredit' => 0],
            ['account_id' => $kas->id,     'debit' => 0,         'kredit' => 5_000_000],
        ], overrides: ['document_type' => 'manual', 'business_unit_id' => $buArmd->id], date: $today);
        $svc->post($j3);

        // === Jurnal 4: Bayar gaji kantor Rp 10jt (BU=null) ===
        $j4 = $this->makeJournalEntry($company, [
            ['account_id' => $opexGaji->id, 'debit' => 10_000_000, 'kredit' => 0],
            ['account_id' => $kas->id,      'debit' => 0,          'kredit' => 10_000_000],
        ], overrides: ['document_type' => 'manual', 'business_unit_id' => null], date: $today);
        $svc->post($j4);

        // === Panggil report ===
        $report = app(CashFlowLakService::class)->getReport($company->id, 2026, 6);

        // Saldo awal — belum ada saldo_awal jurnal
        $this->assertSame(0.0, (float) $report['saldoAwal']);

        // === Penerimaan ===
        $penerimaanByLabel = collect($report['penerimaan'])->keyBy('label');

        $this->assertSame(20_000_000.0, (float) $penerimaanByLabel['PIUTANG ALAT BERAT']['amount'],
            'Terima piutang RENT harus muncul di PIUTANG ALAT BERAT');
        $this->assertSame(100_000_000.0, (float) $penerimaanByLabel['MODAL']['amount'],
            'Setoran modal harus muncul di MODAL');
        $this->assertSame(0.0, (float) $penerimaanByLabel['PIUTANG EXPEDISI']['amount'],
            'Tidak ada piutang ARMD di test ini');

        $this->assertSame(120_000_000.0, (float) $report['totalPenerimaan']);

        // === Pengeluaran Expedisi (ARMD) ===
        $expByLabel = collect($report['pengeluaran']['pengeluaran_expedisi']['items'])->keyBy('label');
        $this->assertSame(5_000_000.0, (float) $expByLabel['BIAYA BBM EXP']['amount'],
            'Bayar BBM Dump Truck harus muncul di BIAYA BBM EXP');
        $this->assertSame(5_000_000.0, (float) $report['pengeluaran']['pengeluaran_expedisi']['total']);

        // === Pengeluaran Kantor (BU=null) ===
        $kantorByLabel = collect($report['pengeluaran']['pengeluaran_kantor']['items'])->keyBy('label');
        $this->assertSame(10_000_000.0, (float) $kantorByLabel['GAJI KANTOR']['amount'],
            'Bayar gaji kantor harus muncul di GAJI KANTOR');

        // === Total & Saldo Akhir ===
        $this->assertSame(15_000_000.0, (float) $report['totalPengeluaran']);
        $this->assertSame(105_000_000.0, (float) $report['saldoAkhir'],
            'Saldo akhir = 0 + 120jt - 15jt = 105jt');
    }

    public function test_lak_report_kosong_kalau_belum_ada_akun_kas(): void
    {
        $company = $this->createTenant();
        // Hapus akun kas untuk simulasi tenant belum di-setup COA
        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('role', [AccountRole::Cash->value, AccountRole::CashPetty->value])
            ->delete();
        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('code', ['111100', '111110'])
            ->delete();

        $report = app(CashFlowLakService::class)->getReport($company->id, 2026, 6);

        $this->assertSame(0.0, (float) $report['saldoAwal']);
        $this->assertSame(0.0, (float) $report['totalPenerimaan']);
        $this->assertSame(0.0, (float) $report['totalPengeluaran']);
        $this->assertSame([], $report['penerimaan']);
        $this->assertSame([], $report['pengeluaran']);
    }
}
