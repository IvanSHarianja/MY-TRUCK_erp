<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalService
{
    /**
     * Auto-generate nomor jurnal per company per bulan.
     * Format: J{YY}{MM}-{NNN}, contoh: J2606-001
     */
    public function generateEntryNumber(Company $company, CarbonInterface $date): string
    {
        $prefix = sprintf('J%02d%02d-', $date->format('y'), $date->format('m'));

        $lastNumber = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('entry_number', 'like', $prefix . '%')
            ->orderByDesc('entry_number')
            ->value('entry_number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, -3)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Validasi total debit = total kredit.
     * Setiap baris harus debit XOR kredit (tidak boleh keduanya, tidak boleh keduanya nol).
     *
     * @param array<int, array{debit?: float|int|string|null, kredit?: float|int|string|null}> $lines
     */
    public function validateBalance(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => 'Jurnal minimal harus punya 2 baris (debit & kredit).',
            ]);
        }

        $totalDebit = 0.0;
        $totalKredit = 0.0;

        foreach ($lines as $i => $line) {
            $debit  = (float) ($line['debit'] ?? 0);
            $kredit = (float) ($line['kredit'] ?? 0);

            if ($debit > 0 && $kredit > 0) {
                throw ValidationException::withMessages([
                    "lines.$i" => 'Baris tidak boleh berisi Debit dan Kredit sekaligus.',
                ]);
            }

            if ($debit <= 0 && $kredit <= 0) {
                throw ValidationException::withMessages([
                    "lines.$i" => 'Baris harus berisi salah satu: Debit atau Kredit (> 0).',
                ]);
            }

            $totalDebit  += $debit;
            $totalKredit += $kredit;
        }

        if (round($totalDebit, 2) !== round($totalKredit, 2)) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'Jurnal tidak balance! Debit Rp %s ≠ Kredit Rp %s (selisih Rp %s).',
                    number_format($totalDebit, 2, ',', '.'),
                    number_format($totalKredit, 2, ',', '.'),
                    number_format(abs($totalDebit - $totalKredit), 2, ',', '.'),
                ),
            ]);
        }
    }

    /**
     * Pastikan semua akun yang dipakai di lines adalah POSTABLE (bukan HEADER).
     * Akun header (yang punya children) tidak boleh di-post langsung.
     *
     * @param array<int, int> $accountIds
     */
    public function validateAccountsArePostable(array $accountIds): void
    {
        if (empty($accountIds)) return;

        // Cari akun-akun yang punya children (= header)
        $headerAccounts = Account::withoutGlobalScopes()
            ->whereIn('id', $accountIds)
            ->headers()
            ->get(['id', 'code', 'name']);

        if ($headerAccounts->isNotEmpty()) {
            $names = $headerAccounts->map(fn ($a) => "[{$a->code}] {$a->name}")->implode(', ');
            throw ValidationException::withMessages([
                'lines' => "Akun berikut adalah HEADER (punya sub-akun) dan tidak bisa di-post langsung di jurnal: {$names}. Pilih sub-akun spesifiknya.",
            ]);
        }
    }

    /**
     * Pastikan periode (year-month) belum di-close.
     */
    public function assertPeriodOpen(Company $company, int $year, int $month): void
    {
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        if ($period && $period->isClosed()) {
            throw ValidationException::withMessages([
                'entry_date' => "Periode {$year}-{$month} sudah ditutup. Tidak bisa input jurnal baru.",
            ]);
        }
    }

    /**
     * Post jurnal: draft → posted.
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        if (! $entry->isDraft()) {
            throw ValidationException::withMessages([
                'status' => "Hanya jurnal status DRAFT yang bisa di-post (status sekarang: {$entry->status}).",
            ]);
        }

        $entry->loadMissing('lines');

        $this->validateBalance($entry->lines->map(fn ($l) => [
            'debit'  => $l->debit,
            'kredit' => $l->kredit,
        ])->toArray());

        // Pastikan semua akun di lines adalah POSTABLE (bukan HEADER)
        $this->validateAccountsArePostable(
            $entry->lines->pluck('account_id')->unique()->all()
        );

        $this->assertPeriodOpen(
            Company::findOrFail($entry->company_id),
            $entry->period_year,
            $entry->period_month,
        );

        return DB::transaction(function () use ($entry) {
            $entry->update([
                'status'       => 'posted',
                'posted_by'    => Auth::id(),
                'posted_at'    => now(),
                'total_amount' => $entry->total_debit,
            ]);

            return $entry->refresh();
        });
    }

    /**
     * Void jurnal yang sudah di-posted: buat jurnal pembalik.
     * Jurnal asli tetap, tapi statusnya jadi 'void' dan reversed_by_id ke jurnal pembalik.
     */
    public function void(JournalEntry $entry, ?string $reason = null): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw ValidationException::withMessages([
                'status' => "Hanya jurnal status POSTED yang bisa di-void (status sekarang: {$entry->status}).",
            ]);
        }

        $entry->loadMissing('lines');

        return DB::transaction(function () use ($entry, $reason) {
            $reverseDate = Carbon::today();

            $reverse = JournalEntry::create([
                'company_id'       => $entry->company_id,
                'entry_number'     => $this->generateEntryNumber(
                    Company::findOrFail($entry->company_id),
                    $reverseDate,
                ),
                'entry_date'       => $reverseDate,
                'document_number'  => 'REV-' . $entry->entry_number,
                'document_type'    => 'pembalik',
                'business_unit_id' => $entry->business_unit_id,
                'description'      => 'Jurnal pembalik untuk ' . $entry->entry_number
                    . ($reason ? '. Alasan: ' . $reason : ''),
                'period_year'      => $reverseDate->year,
                'period_month'     => $reverseDate->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $entry->created_by,
                'posted_by'        => Auth::id() ?? $entry->created_by,
                'posted_at'        => now(),
                'total_amount'     => $entry->total_amount,
            ]);

            // Balik debit/kredit
            foreach ($entry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reverse->id,
                    'account_id'       => $line->account_id,
                    'description'      => 'Pembalik: ' . ($line->description ?? ''),
                    'debit'            => $line->kredit,
                    'kredit'           => $line->debit,
                    'sort_order'       => $line->sort_order,
                ]);
            }

            $entry->update([
                'status'         => 'void',
                'reversed_by_id' => $reverse->id,
            ]);

            return $entry->refresh();
        });
    }

    /**
     * Hapus draft jurnal (hanya untuk status draft).
     */
    public function deleteDraft(JournalEntry $entry): void
    {
        if (! $entry->isDraft()) {
            throw ValidationException::withMessages([
                'status' => "Hanya jurnal DRAFT yang bisa dihapus. Untuk jurnal posted, gunakan Void.",
            ]);
        }

        $entry->delete();
    }

    /**
     * Atomic create JournalEntry + lines dengan retry pada unique-constraint collision.
     *
     * Race condition di `generateEntryNumber()` (SELECT max → INSERT) bisa menghasilkan
     * entry_number duplikat saat dua request bersamaan. Karena tabel `journal_entries`
     * punya unique (company_id, entry_number), INSERT yang collision akan throw
     * UniqueConstraintViolationException — kita tangkap & retry dengan nomor baru.
     *
     * Pola pakai:
     * ```php
     * $journal = $journalService->createEntryWithLines(
     *     $company, $date,
     *     entryDataFactory: fn (string $entryNumber) => [
     *         'company_id'       => $company->id,
     *         'entry_number'     => $entryNumber,
     *         'entry_date'       => $date,
     *         // ... field lain
     *     ],
     *     linesFactory: fn (JournalEntry $entry) => [
     *         ['account_id' => 1, 'debit' => 1000, 'kredit' => 0, 'sort_order' => 1],
     *         ['account_id' => 2, 'debit' => 0, 'kredit' => 1000, 'sort_order' => 2],
     *     ],
     * );
     * ```
     *
     * @param  Company         $company
     * @param  CarbonInterface $date
     * @param  callable        $entryDataFactory  fn(string $entryNumber): array $entryData
     * @param  callable        $linesFactory      fn(JournalEntry $entry): array<int, array>
     * @param  int             $maxAttempts       default 5, exponential backoff
     */
    public function createEntryWithLines(
        Company $company,
        CarbonInterface $date,
        callable $entryDataFactory,
        callable $linesFactory,
        int $maxAttempts = 5,
    ): JournalEntry {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                return DB::transaction(function () use ($company, $date, $entryDataFactory, $linesFactory) {
                    $entryNumber = $this->generateEntryNumber($company, $date);

                    /** @var JournalEntry $entry */
                    $entry = JournalEntry::create($entryDataFactory($entryNumber));

                    foreach ($linesFactory($entry) as $i => $lineData) {
                        JournalEntryLine::create(array_merge(
                            ['journal_entry_id' => $entry->id, 'sort_order' => $i + 1],
                            $lineData,
                        ));
                    }

                    return $entry->load('lines.account');
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Retry dengan exponential backoff + jitter (10–50ms × 2^attempt)
                $lastException = $e;
                $baseSleep = 10_000 * (2 ** ($attempt - 1));
                usleep(random_int($baseSleep, $baseSleep + 40_000));
            }
        }

        // Setelah maxAttempts, throw exception terakhir
        throw $lastException ?? new \RuntimeException(
            "Gagal generate entry_number unik setelah {$maxAttempts} percobaan."
        );
    }
}
