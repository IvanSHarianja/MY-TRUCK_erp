<?php

namespace App\Services\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tutup Buku tahunan (keputusan Ivan 2026-11-17: tombol Owner + pengaman).
 *
 * Tutup buku {tahun}:
 *   1. Jurnal PENUTUP tanggal 31 Des: setiap akun pendapatan & beban dinolkan,
 *      selisihnya (laba/rugi bersih) masuk Laba Ditahan.
 *      document_type 'penutup', No. Bukti "TUTUP-{tahun}".
 *   2. Ke-12 periode tahun itu dikunci (accounting_periods.status = closed).
 *
 * Laporan: L/R mengabaikan jurnal penutup (laba tahun itu tetap terlihat);
 * Neraca Saldo & Neraca memuatnya. Selama tahun lalu BELUM ditutup, Neraca
 * menampilkan "Laba tahun lalu (belum ditutup buku)" supaya tetap balance —
 * dulu laba tahun lalu hilang dan Neraca selisih mulai tahun kedua.
 *
 * Buka kembali = void jurnal penutup + buka kunci 12 periode. Hanya tahun
 * TERAKHIR yang ditutup yang boleh dibuka (tahun sesudahnya harus terbuka).
 */
class YearEndClosingService
{
    public function __construct(
        private JournalService $journals,
        private IncomeStatementService $incomeStatement,
    ) {}

    public static function documentNumber(int $year): string
    {
        return "TUTUP-{$year}";
    }

    /**
     * Status per tahun yang punya jurnal: laba bersih, sudah ditutup atau belum.
     *
     * @return list<array{year: int, profit: float, closed: bool, journal_id: ?int, can_close: bool, can_reopen: bool}>
     */
    public function years(int $companyId): array
    {
        $range = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->selectRaw('MIN(period_year) AS min_y, MAX(period_year) AS max_y')
            ->first();

        $current = (int) now()->year;
        $first = (int) ($range->min_y ?? $current);
        $last  = max($current, (int) ($range->max_y ?? $current));

        $rows = [];
        for ($y = $first; $y <= $last; $y++) {
            $journal = $this->closingJournal($companyId, $y);
            $rows[] = [
                'year'       => $y,
                'profit'     => $this->incomeStatement->getNetProfit($companyId, $y, null),
                'closed'     => $journal !== null || $this->periodsLocked($companyId, $y),
                'journal_id' => $journal?->id,
            ];
        }

        // Tutup berurutan dari tahun terlama; buka kembali hanya tahun terakhir yang ditutup.
        $firstOpen = collect($rows)->firstWhere('closed', false)['year'] ?? null;
        $lastClosed = collect($rows)->where('closed', true)->last()['year'] ?? null;

        return array_map(fn ($r) => $r + [
            'can_close'  => ! $r['closed'] && $r['year'] === $firstOpen && $r['year'] < $current,
            'can_reopen' => $r['closed'] && $r['year'] === $lastClosed,
        ], $rows);
    }

    public function close(Company $company, int $year): ?JournalEntry
    {
        if ($year >= (int) now()->year) {
            throw ValidationException::withMessages([
                'year' => "Tahun {$year} belum berakhir — tutup buku dilakukan setelah 31 Desember {$year}.",
            ]);
        }

        return DB::transaction(function () use ($company, $year) {
            // Kunci baris perusahaan: cegah dua klik Tutup Buku bersamaan.
            Company::withoutGlobalScopes()->whereKey($company->id)->lockForUpdate()->first();

            $status = collect($this->years($company->id))->firstWhere('year', $year);
            if ($status && $status['closed']) {
                throw ValidationException::withMessages(['year' => "Tahun {$year} sudah ditutup."]);
            }
            $earlierOpen = collect($this->years($company->id))->first(fn ($r) => $r['year'] < $year && ! $r['closed']);
            if ($earlierOpen) {
                throw ValidationException::withMessages([
                    'year' => "Tutup dulu tahun {$earlierOpen['year']} — tutup buku harus berurutan.",
                ]);
            }

            $journal = $this->postClosingJournal($company, $year);
            $this->lockYear($company->id, $year, true);

            activity()->performedOn($company)->causedBy(Auth::user())
                ->event('tutup_buku')
                ->withProperties(['tahun' => $year, 'jurnal' => $journal?->entry_number])
                ->log("Tutup buku tahun {$year}");

            return $journal;
        });
    }

    public function reopen(Company $company, int $year, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan membuka kembali (minimal 5 karakter).']);
        }

        DB::transaction(function () use ($company, $year, $reason) {
            Company::withoutGlobalScopes()->whereKey($company->id)->lockForUpdate()->first();

            $status = collect($this->years($company->id))->firstWhere('year', $year);
            if (! $status || ! $status['can_reopen']) {
                throw ValidationException::withMessages([
                    'year' => "Tahun {$year} tidak bisa dibuka: hanya tahun terakhir yang ditutup yang boleh dibuka kembali.",
                ]);
            }

            if ($journal = $this->closingJournal($company->id, $year)) {
                $this->journals->void($journal, "Buka kembali buku {$year}: {$reason}");
            }
            $this->lockYear($company->id, $year, false);

            activity()->performedOn($company)->causedBy(Auth::user())
                ->event('buka_buku')
                ->withProperties(['tahun' => $year, 'alasan' => $reason])
                ->log("Buka kembali buku tahun {$year}");
        });
    }

    /**
     * Laba/rugi tahun-tahun SEBELUM $year yang belum dipindah ke Laba Ditahan
     * (= saldo akun pendapatan & beban s.d. akhir tahun sebelumnya, termasuk
     * efek jurnal penutup — tahun yang sudah ditutup bernilai 0).
     */
    public function unclosedProfitBefore(int $companyId, int $year): float
    {
        return round((float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->where('je.period_year', '<', $year)
            ->whereIn('a.category', ['pendapatan', 'beban'])
            ->selectRaw('COALESCE(SUM(jl.kredit), 0) - COALESCE(SUM(jl.debit), 0) AS net')
            ->value('net'), 2);
    }

    /**
     * Laba tahun $year s.d. $month yang MASIH di akun L/R — dipakai Neraca.
     * Berbeda dengan Laporan L/R: jurnal penutup IKUT dihitung, jadi setelah
     * tutup buku nilainya 0 (sudah di Laba Ditahan) dan tidak terhitung dua kali.
     */
    public function profitStillInIncomeAccounts(int $companyId, int $year, ?int $month = null): float
    {
        return round((float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->where('je.period_year', $year)
            ->when($month !== null, fn ($q) => $q->where('je.period_month', '<=', $month))
            ->whereIn('a.category', ['pendapatan', 'beban'])
            ->selectRaw('COALESCE(SUM(jl.kredit), 0) - COALESCE(SUM(jl.debit), 0) AS net')
            ->value('net'), 2);
    }

    /** Tahun-tahun sebelum $year yang belum ditutup (untuk peringatan di Neraca). */
    public function openYearsBefore(int $companyId, int $year): array
    {
        return collect($this->years($companyId))
            ->filter(fn ($r) => $r['year'] < $year && ! $r['closed'] && abs($r['profit']) > 0.005)
            ->pluck('year')->values()->all();
    }

    private function postClosingJournal(Company $company, int $year): ?JournalEntry
    {
        // Saldo setiap akun L/R tahun itu (tanpa jurnal penutup lama yang di-void).
        $balances = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $company->id)
            ->where('je.status', 'posted')
            ->whereNotIn('je.document_type', ['pembalik', 'penutup'])
            ->where('je.period_year', $year)
            ->whereIn('a.category', ['pendapatan', 'beban'])
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, SUM(jl.debit) - SUM(jl.kredit) AS net')
            ->get()
            ->filter(fn ($r) => abs((float) $r->net) >= 0.005);

        if ($balances->isEmpty()) {
            return null;    // tidak ada transaksi L/R — cukup kunci periode
        }

        $labaDitahan = Account::findByRoleOrCode(AccountRole::EquityLabaDitahan, '331300', $company->id);
        if (! $labaDitahan) {
            throw ValidationException::withMessages([
                'account' => 'Akun Laba Ditahan tidak ditemukan/postable. Beri Peran "Laba Ditahan" pada akun ekuitas yang sesuai di Daftar Akun.',
            ]);
        }

        // Nolkan tiap akun: saldo debit → dikredit, saldo kredit → didebit.
        $lines = $balances->map(fn ($r) => [
            'account_id'  => (int) $r->account_id,
            'description' => "Tutup buku {$year}",
            'debit'       => (float) $r->net < 0 ? round(-(float) $r->net, 2) : 0,
            'kredit'      => (float) $r->net > 0 ? round((float) $r->net, 2) : 0,
        ])->values()->all();

        $profit = round(-(float) $balances->sum('net'), 2);   // kredit − debit = laba
        $lines[] = [
            'account_id'  => $labaDitahan->id,
            'description' => $profit >= 0 ? "Laba bersih {$year} ke Laba Ditahan" : "Rugi bersih {$year} ke Laba Ditahan",
            'debit'       => $profit < 0 ? -$profit : 0,
            'kredit'      => $profit > 0 ? $profit : 0,
        ];

        $date = Carbon::create($year, 12, 31);
        $total = round(array_sum(array_column($lines, 'debit')), 2);

        return $this->journals->createEntryWithLines(
            company:          $company,
            date:             $date,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'      => $company->id,
                'entry_number'    => $entryNumber,
                'entry_date'      => $date,
                'document_number' => self::documentNumber($year),
                'document_type'   => 'penutup',
                'description'     => "Jurnal penutup tahun {$year} — saldo pendapatan & beban dipindah ke Laba Ditahan",
                'period_year'     => $year,
                'period_month'    => 12,
                'status'          => 'posted',
                'created_by'      => Auth::id(),
                'posted_by'       => Auth::id(),
                'posted_at'       => now(),
                'total_amount'    => $total,
            ],
            linesFactory: fn () => $lines,
        );
    }

    private function closingJournal(int $companyId, int $year): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('document_type', 'penutup')
            ->where('document_number', self::documentNumber($year))
            ->where('status', 'posted')
            ->first();
    }

    /** Tahun tanpa transaksi L/R dianggap tertutup bila ke-12 periodenya terkunci. */
    private function periodsLocked(int $companyId, int $year): bool
    {
        return AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('period_year', $year)
            ->where('status', 'closed')
            ->count() === 12;
    }

    private function lockYear(int $companyId, int $year, bool $lock): void
    {
        foreach (range(1, 12) as $m) {
            AccountingPeriod::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'period_year' => $year, 'period_month' => $m],
                [
                    'status'    => $lock ? 'closed' : 'open',
                    'closed_by' => $lock ? Auth::id() : null,
                    'closed_at' => $lock ? now() : null,
                ],
            );
        }
    }
}
