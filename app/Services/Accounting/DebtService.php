<?php

namespace App\Services\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\JournalEntry;
use App\Models\MaterialPurchase;
use App\Models\User;
use App\Support\DocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hutang & cicilan perusahaan.
 *
 *   create()   — catat hutang + jadwal cicilan (pokok rata, bunga flat % per tahun).
 *                Bila dana pinjaman masuk ke rekening ("Dana diterima di"), jurnal
 *                Dr Kas / Cr Utang dibuat. Kosong = hutang lama yang sudah ada di
 *                saldo awal / jurnal sebelumnya (tanpa jurnal baru).
 *   pay()      — bayar 1 cicilan: Dr Utang (pokok) + Dr Beban Bunga (bunga + denda) / Cr Kas.
 *   cancelPayment() — void jurnal pembayaran, cicilan kembali "belum".
 *   createForPurchase() — pembelian material kredit → hutang vendor 1x bayar
 *                (jurnal utangnya sudah dibuat MaterialPurchaseService).
 */
class DebtService
{
    /**
     * True selama service ini me-void jurnalnya sendiri (batal bayar / hapus).
     * Void jurnal hutang dari Jurnal Umum ditolak JournalEntryObserver —
     * status cicilan tidak ikut berubah dan jadi tidak sinkron dengan buku.
     */
    public static bool $voiding = false;

    public function __construct(private JournalService $journals) {}

    private function voidOwnJournal(JournalEntry $journal, string $reason): void
    {
        self::$voiding = true;
        try {
            $this->journals->void($journal, $reason);
        } finally {
            self::$voiding = false;
        }
    }

    /**
     * @param array{jenis:string, kreditur:string, vendor_id?:?int, payable_account_id:int, pokok:mixed,
     *              bunga_pct?:mixed, tenor_bulan:int, tanggal_mulai:mixed, jatuh_tempo_pertama:mixed,
     *              disbursement_account_id?:?int, notes?:?string} $data
     */
    public function create(Company $company, User $user, array $data): Debt
    {
        $jenis = (string) ($data['jenis'] ?? '');
        if (! array_key_exists($jenis, Debt::JENIS)) {
            throw ValidationException::withMessages(['jenis' => 'Pilih jenis hutang.']);
        }
        $kreditur = trim((string) ($data['kreditur'] ?? ''));
        if ($kreditur === '') {
            throw ValidationException::withMessages(['kreditur' => 'Isi nama kreditur (bank / leasing / vendor).']);
        }
        $payable = Account::withoutGlobalScopes()->where('company_id', $company->id)->find($data['payable_account_id'] ?? 0);
        if (! $payable || $payable->category !== 'kewajiban' || ! $payable->isPostable()) {
            throw ValidationException::withMessages(['payable_account_id' => 'Pilih akun hutang (kategori Kewajiban, sub-akun).']);
        }
        $pokok = round((float) ($data['pokok'] ?? 0));
        if ($pokok <= 0) {
            throw ValidationException::withMessages(['pokok' => 'Pokok hutang harus lebih dari 0.']);
        }
        $tenor = (int) ($data['tenor_bulan'] ?? 0);
        if ($tenor < 1 || $tenor > 360) {
            throw ValidationException::withMessages(['tenor_bulan' => 'Tenor 1 – 360 bulan.']);
        }
        $bunga = (float) ($data['bunga_pct'] ?? 0);
        if ($bunga < 0 || $bunga > 100) {
            throw ValidationException::withMessages(['bunga_pct' => 'Bunga 0 – 100% per tahun.']);
        }
        $mulai = Carbon::parse($data['tanggal_mulai'] ?? today());
        $tempo = Carbon::parse($data['jatuh_tempo_pertama'] ?? $mulai->copy()->addMonthNoOverflow());
        if ($tempo->lt($mulai)) {
            throw ValidationException::withMessages(['jatuh_tempo_pertama' => 'Jatuh tempo pertama tidak boleh sebelum tanggal mulai.']);
        }

        $cash = null;
        if (! empty($data['disbursement_account_id'])) {
            $cash = $this->cashAccount($company, (int) $data['disbursement_account_id'], 'disbursement_account_id');
            $this->journals->assertPeriodOpen($company, $mulai->year, $mulai->month);
        }

        return DB::transaction(function () use ($company, $user, $data, $jenis, $kreditur, $payable, $pokok, $tenor, $bunga, $mulai, $tempo, $cash) {
            $debt = DocumentSequence::createWithRetry(fn () => Debt::create([
                'company_id'          => $company->id,
                'debt_number'         => $this->nextNumber($company, $mulai),
                'jenis'               => $jenis,
                'kreditur'            => mb_substr($kreditur, 0, 150),
                'vendor_id'           => $data['vendor_id'] ?? null,
                'payable_account_id'  => $payable->id,
                'pokok'               => $pokok,
                'bunga_pct'           => $bunga,
                'tenor_bulan'         => $tenor,
                'tanggal_mulai'       => $mulai->toDateString(),
                'jatuh_tempo_pertama' => $tempo->toDateString(),
                'status'              => 'aktif',
                'source_type'         => $data['source_type'] ?? null,
                'source_id'           => $data['source_id'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $user->id,
            ]));

            foreach ($this->schedule($pokok, $bunga, $tenor, $tempo) as $row) {
                $debt->installments()->create($row + ['company_id' => $company->id]);
            }

            if ($cash) {
                $journal = $this->post($company, $mulai, $debt->debt_number, 'bkm', $debt,
                    "Pencairan {$debt->debt_number} — {$kreditur}", $user->id, [
                        ['account_id' => $cash->id, 'description' => "Dana pinjaman dari {$kreditur}", 'debit' => $pokok, 'kredit' => 0],
                        ['account_id' => $payable->id, 'description' => "Hutang {$debt->debt_number}", 'debit' => 0, 'kredit' => $pokok],
                    ]);
                $debt->update(['disbursement_journal_id' => $journal->id]);
            }

            return $debt->refresh();
        });
    }

    /**
     * Pokok dibagi rata (sisa pembulatan di cicilan terakhir); bunga flat per bulan.
     *
     * @return list<array{ke:int, due_date:string, pokok:float, bunga:float}>
     */
    public function schedule(float $pokok, float $bungaPctYear, int $tenor, CarbonInterface $firstDue): array
    {
        $perPokok = floor($pokok / $tenor);
        $perBunga = round($pokok * $bungaPctYear / 100 / 12);
        $rows = [];
        for ($i = 0; $i < $tenor; $i++) {
            $rows[] = [
                'ke'       => $i + 1,
                'due_date' => Carbon::parse($firstDue)->addMonthsNoOverflow($i)->toDateString(),
                'pokok'    => $i === $tenor - 1 ? $pokok - $perPokok * ($tenor - 1) : $perPokok,
                'bunga'    => $perBunga,
            ];
        }

        return $rows;
    }

    /** Pembelian material kredit → hutang vendor 1x bayar (tanpa jurnal baru). */
    public function createForPurchase(MaterialPurchase $purchase, CarbonInterface|string|null $dueDate, User $user): Debt
    {
        $company = Company::withoutGlobalScopes()->findOrFail($purchase->company_id);
        $payable = Account::findByRoleOrCode(AccountRole::PayableVendor, '221100', $company->id);
        $purchase->loadMissing(['vendor', 'material']);
        $date = Carbon::parse($purchase->purchase_date);
        $due = $dueDate ? Carbon::parse($dueDate) : $date->copy()->addDays(30);

        return $this->create($company, $user, [
            'jenis'               => 'vendor',
            'kreditur'            => $purchase->vendor?->name ?? 'Vendor',
            'vendor_id'           => $purchase->vendor_id,
            'payable_account_id'  => $payable?->id,
            'pokok'               => $purchase->total_amount,
            'bunga_pct'           => 0,
            'tenor_bulan'         => 1,
            'tanggal_mulai'       => $date,
            'jatuh_tempo_pertama' => $due->lt($date) ? $date : $due,
            'source_type'         => $purchase->getMorphClass(),
            'source_id'           => $purchase->id,
            'notes'               => "Pembelian kredit {$purchase->purchase_number} — {$purchase->material?->name}",
        ]);
    }

    /**
     * @param array{paid_date:mixed, cash_account_id:int, bunga?:mixed, denda?:mixed} $data
     */
    public function pay(DebtInstallment $installment, User $user, array $data): DebtInstallment
    {
        $company = Company::withoutGlobalScopes()->findOrFail($installment->company_id);
        $cash = $this->cashAccount($company, (int) ($data['cash_account_id'] ?? 0), 'cash_account_id');
        $date = Carbon::parse($data['paid_date'] ?? today());
        $this->journals->assertPeriodOpen($company, $date->year, $date->month);

        $bunga = round((float) ($data['bunga'] ?? $installment->bunga));
        $denda = round((float) ($data['denda'] ?? 0));
        if ($bunga < 0 || $denda < 0) {
            throw ValidationException::withMessages(['bunga' => 'Bunga/denda tidak boleh negatif.']);
        }
        $bebanBunga = null;
        if ($bunga + $denda > 0) {
            $bebanBunga = Account::findByRoleOrCode(AccountRole::OpexBunga, '552700', $company->id)
                ?? throw ValidationException::withMessages(['bunga' => 'Akun Beban Bunga (peran "opex_bunga" / kode 552700) belum ada di Daftar Akun.']);
        }

        return DB::transaction(function () use ($installment, $company, $user, $cash, $date, $bunga, $denda, $bebanBunga) {
            // Kunci hutang dulu (urutan sama dgn delete): bayar dua cicilan terakhir
            // bersamaan dulu bisa meninggalkan status "Berjalan" padahal lunas.
            $debt = Debt::withoutGlobalScopes()->with('payableAccount')->lockForUpdate()->findOrFail($installment->debt_id);
            $locked = DebtInstallment::withoutGlobalScopes()->lockForUpdate()->findOrFail($installment->id);
            if ($locked->isPaid()) {
                throw ValidationException::withMessages(['status' => "Cicilan ke-{$locked->ke} sudah lunas."]);
            }
            $pokok = (float) $locked->pokok;
            $total = $pokok + $bunga + $denda;

            $lines = [['account_id' => $debt->payable_account_id, 'description' => "Pokok cicilan ke-{$locked->ke}", 'debit' => $pokok, 'kredit' => 0]];
            if ($bebanBunga && $bunga + $denda > 0) {
                $lines[] = ['account_id' => $bebanBunga->id, 'description' => 'Bunga' . ($denda > 0 ? ' + denda' : '') . " cicilan ke-{$locked->ke}", 'debit' => $bunga + $denda, 'kredit' => 0];
            }
            $lines[] = ['account_id' => $cash->id, 'description' => "Bayar {$debt->kreditur}", 'debit' => 0, 'kredit' => $total];

            $journal = $this->post($company, $date, "{$debt->debt_number}/{$locked->ke}", 'bkk', $locked,
                "Cicilan ke-{$locked->ke}/{$debt->tenor_bulan} {$debt->debt_number} — {$debt->kreditur}", $user->id, $lines);

            $locked->update([
                'status' => 'lunas', 'paid_date' => $date->toDateString(), 'paid_pokok' => $pokok,
                'paid_bunga' => $bunga, 'denda' => $denda ?: null, 'cash_account_id' => $cash->id,
                'journal_entry_id' => $journal->id, 'paid_by' => $user->id,
            ]);

            $this->refreshStatus($debt);

            return $locked->refresh();
        });
    }

    public function cancelPayment(DebtInstallment $installment, User $user): DebtInstallment
    {
        return DB::transaction(function () use ($installment, $user) {
            Debt::withoutGlobalScopes()->lockForUpdate()->findOrFail($installment->debt_id);
            $locked = DebtInstallment::withoutGlobalScopes()->lockForUpdate()->findOrFail($installment->id);
            if (! $locked->isPaid()) {
                throw ValidationException::withMessages(['status' => "Cicilan ke-{$locked->ke} belum dibayar."]);
            }
            $journal = $locked->journal_entry_id ? JournalEntry::withoutGlobalScopes()->find($locked->journal_entry_id) : null;
            if ($journal && $journal->isPosted()) {
                $this->voidOwnJournal($journal, "Batal bayar cicilan ke-{$locked->ke} oleh {$user->name}");
            }
            $locked->update([
                'status' => 'belum', 'paid_date' => null, 'paid_pokok' => null, 'paid_bunga' => null,
                'denda' => null, 'cash_account_id' => null, 'journal_entry_id' => null, 'paid_by' => null,
            ]);
            $this->refreshStatus(Debt::withoutGlobalScopes()->findOrFail($locked->debt_id));

            return $locked->refresh();
        });
    }

    /** Hapus hutang — hanya bila belum ada cicilan dibayar. Jurnal pencairan di-void. */
    public function delete(Debt $debt): void
    {
        DB::transaction(function () use ($debt) {
            $locked = Debt::withoutGlobalScopes()->lockForUpdate()->findOrFail($debt->id);
            if ($locked->installments()->where('status', 'lunas')->exists()) {
                throw ValidationException::withMessages(['debt' => 'Sudah ada cicilan dibayar. Batalkan pembayarannya dulu.']);
            }
            if ($locked->source_type) {
                throw ValidationException::withMessages(['debt' => 'Hutang ini dibuat otomatis dari pembelian kredit — tidak bisa dihapus.']);
            }
            $journal = $locked->disbursement_journal_id ? JournalEntry::withoutGlobalScopes()->find($locked->disbursement_journal_id) : null;
            if ($journal && $journal->isPosted()) {
                $this->voidOwnJournal($journal, "Hapus hutang {$locked->debt_number}");
            }
            $locked->installments()->delete();
            $locked->delete();
        });
    }

    /** Sisa pokok belum dibayar. */
    public function outstanding(Debt $debt): float
    {
        return (float) $debt->installments()->where('status', 'belum')->sum('pokok');
    }

    private function refreshStatus(Debt $debt): void
    {
        $open = $debt->installments()->where('status', 'belum')->exists();
        $debt->update(['status' => $open ? 'aktif' : 'lunas']);
    }

    private function cashAccount(Company $company, int $id, string $field): Account
    {
        if (! in_array($id, array_map('intval', Account::cashAccountIds($company->id)), true)) {
            throw ValidationException::withMessages([$field => 'Pilih rekening kas/bank.']);
        }

        return Account::withoutGlobalScopes()->findOrFail($id);
    }

    private function nextNumber(Company $company, CarbonInterface $date): string
    {
        return DocumentSequence::next(
            Debt::withoutGlobalScopes()->where('company_id', $company->id),
            'debt_number', 'HT' . $date->format('ym') . '-', 3,
        );
    }

    private function post(Company $company, CarbonInterface $date, string $docNumber, string $type, $source, string $description, int $userId, array $lines): JournalEntry
    {
        $total = (float) collect($lines)->sum('debit');

        return $this->journals->createEntryWithLines(
            company:          $company,
            date:             $date,
            entryDataFactory: fn (string $n): array => [
                'company_id'      => $company->id,
                'entry_number'    => $n,
                'entry_date'      => $date,
                'document_number' => $docNumber,
                'document_type'   => $type,
                'source_type'     => $source->getMorphClass(),
                'source_id'       => $source->id,
                'description'     => $description,
                'period_year'     => $date->year,
                'period_month'    => $date->month,
                'status'          => 'posted',
                'created_by'      => $userId,
                'posted_by'       => $userId,
                'posted_at'       => now(),
                'total_amount'    => $total,
            ],
            linesFactory: fn (): array => $lines,
        );
    }
}
