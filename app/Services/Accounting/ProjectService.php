<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ProjectProgress;
use App\Models\ProjectTermin;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(
        private JournalService $journalService,
        private InvoiceService $invoiceService,
    ) {}

    /**
     * Auto-generate nomor proyek per company per tahun.
     * Format: PR{YY}-{NNNN}, contoh: PR26-0001
     */
    public function generateProjectNumber(Company $company, ?CarbonInterface $date = null): string
    {
        $date ??= Carbon::today();
        $prefix = sprintf('PR%02d-', $date->format('y'));

        $lastNumber = Project::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('project_number', 'like', $prefix . '%')
            ->orderByDesc('project_number')
            ->value('project_number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Update progress fisik proyek + simpan history.
     */
    public function updateProgress(
        Project $project,
        float $progressPct,
        ?string $notes = null,
        ?CarbonInterface $date = null,
    ): ProjectProgress {
        if ($progressPct < 0 || $progressPct > 100) {
            throw ValidationException::withMessages([
                'progress_pct' => 'Progress harus antara 0% sampai 100%.',
            ]);
        }

        if ($progressPct < (float) $project->progress_pct) {
            throw ValidationException::withMessages([
                'progress_pct' => "Progress tidak boleh mundur. Saat ini: {$project->progress_pct}%.",
            ]);
        }

        return DB::transaction(function () use ($project, $progressPct, $notes, $date) {
            $history = ProjectProgress::create([
                'company_id'   => $project->company_id,
                'project_id'   => $project->id,
                'update_date'  => $date ? Carbon::parse($date) : Carbon::today(),
                'progress_pct' => $progressPct,
                'notes'        => $notes,
                'created_by'   => Auth::id() ?? $project->created_by,
            ]);

            $project->update([
                'progress_pct' => $progressPct,
                'status'       => $progressPct >= 100 ? 'selesai' : $project->status,
                'ended_at'     => $progressPct >= 100 ? Carbon::today() : $project->ended_at,
            ]);

            return $history;
        });
    }

    /**
     * Terima Uang Muka (DP) proyek.
     * Auto-jurnal: Dr Kas/Bank / Cr Uang Muka Proyek (221170)
     */
    public function terimaDP(
        Project $project,
        Account $cashAccount,
        float $amount,
        ?CarbonInterface $date = null,
        ?string $notes = null,
    ): JournalEntry {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal DP harus lebih dari 0.',
            ]);
        }

        // Validasi: DP baru tidak boleh menyebabkan total DP + tertagih > nilai kontrak.
        // Formula:
        //   tertagih_nilai   = nilai_kontrak × tertagih_pct / 100
        //   sisa yang bisa   = nilai_kontrak - tertagih_nilai - dp_diterima (existing)
        //   amount DP baru   ≤ sisa yang bisa
        $tertagihNilai = round((float) $project->nilai_kontrak * (float) $project->tertagih_pct / 100, 2);
        $sisaNilai     = round((float) $project->nilai_kontrak - $tertagihNilai - (float) $project->dp_diterima, 2);

        if (round($amount, 2) > $sisaNilai) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'DP Rp %s melebihi sisa nilai kontrak yang bisa diterima (Rp %s). '
                    . 'Nilai kontrak Rp %s, sudah tertagih Rp %s (%s%%), DP diterima Rp %s.',
                    number_format($amount, 0, ',', '.'),
                    number_format(max(0, $sisaNilai), 0, ',', '.'),
                    number_format((float) $project->nilai_kontrak, 0, ',', '.'),
                    number_format($tertagihNilai, 0, ',', '.'),
                    number_format((float) $project->tertagih_pct, 1, ',', '.'),
                    number_format((float) $project->dp_diterima, 0, ',', '.'),
                ),
            ]);
        }

        $company = Company::findOrFail($project->company_id);
        $dpDate  = $date ? Carbon::parse($date) : Carbon::today();

        $this->journalService->assertPeriodOpen($company, $dpDate->year, $dpDate->month);

        // Validasi cash account user pilih sudah postable
        if (! $cashAccount->isPostable()) {
            throw ValidationException::withMessages([
                'cash_account_id' => "Akun [{$cashAccount->code}] {$cashAccount->name} adalah HEADER. Pilih sub-akun spesifik.",
            ]);
        }

        // Akun Uang Muka Proyek (221170) — fallback ke first child kalau HEADER
        // Sprint 2.5: role-based
        $uangMuka = Account::findByRoleOrCode(\App\Enums\AccountRole::UangMukaProyek, '221170', $company->id);

        if (! $uangMuka) {
            throw ValidationException::withMessages([
                'account' => 'Akun Uang Muka Proyek (221170) tidak ditemukan/postable. '
                    . 'Pastikan COA sudah ter-sync atau tambah sub-akun bila sudah jadi HEADER.',
            ]);
        }

        // BusinessUnit BONG
        $bongUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'BONG')
            ->first();

        return DB::transaction(function () use (
            $project, $cashAccount, $amount, $dpDate, $notes, $company, $uangMuka, $bongUnit
        ) {
            $journal = JournalEntry::create([
                'company_id'       => $project->company_id,
                'entry_number'     => $this->journalService->generateEntryNumber($company, $dpDate),
                'entry_date'       => $dpDate,
                'document_number'  => 'DP-' . $project->project_number,
                'document_type'    => 'bkm',
                'business_unit_id' => optional($bongUnit)->id,
                'description'      => 'Uang muka proyek ' . $project->project_number
                    . ' — ' . $project->name
                    . ($notes ? ' — ' . $notes : ''),
                'period_year'      => $dpDate->year,
                'period_month'     => $dpDate->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $project->created_by,
                'posted_by'        => Auth::id() ?? $project->created_by,
                'posted_at'        => now(),
                'total_amount'     => $amount,
            ]);

            // Dr Kas/Bank
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $cashAccount->id,
                'description'      => 'Terima DP ' . $project->project_number,
                'debit'            => $amount,
                'kredit'           => 0,
                'sort_order'       => 1,
            ]);

            // Cr Uang Muka Proyek (kewajiban)
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $uangMuka->id,
                'description'      => 'Uang muka diterima dari ' . optional($project->client)->name,
                'debit'            => 0,
                'kredit'           => $amount,
                'sort_order'       => 2,
            ]);

            // Update DP counter di proyek
            $project->update([
                'dp_diterima' => (float) $project->dp_diterima + $amount,
            ]);

            return $journal;
        });
    }

    /**
     * Tagih termin proyek → bikin Invoice (Dr Piutang Cr Pendapatan Borongan).
     *
     * Validasi:
     * - termin_pct > 0
     * - tertagih_pct + termin_pct ≤ 100
     * - tertagih_pct + termin_pct ≤ progress_pct (tidak boleh melebihi progress fisik)
     * - tertagih_nilai + termin_amount + dp_diterima ≤ nilai_kontrak
     *   (total penerimaan dari klien via DP + tagihan termin tidak boleh melebihi kontrak)
     */
    public function tagihTermin(
        Project $project,
        float $terminPct,
        ?CarbonInterface $invoiceDate = null,
        ?string $description = null,
    ): Invoice {
        if (! $project->isBerjalan()) {
            throw ValidationException::withMessages([
                'status' => "Hanya proyek BERJALAN yang bisa ditagih (status sekarang: {$project->status}).",
            ]);
        }

        if ($terminPct <= 0 || $terminPct > 100) {
            throw ValidationException::withMessages([
                'termin_pct' => 'Persen termin harus antara 0.01% sampai 100%.',
            ]);
        }

        $newTertagih = (float) $project->tertagih_pct + $terminPct;

        if (round($newTertagih, 2) > 100.00) {
            throw ValidationException::withMessages([
                'termin_pct' => "Total tertagih akan menjadi {$newTertagih}% — melebihi 100% nilai kontrak.",
            ]);
        }

        if (round($newTertagih, 2) > round((float) $project->progress_pct, 2) + 0.01) {
            throw ValidationException::withMessages([
                'termin_pct' => sprintf(
                    'Termin %.2f%% + tertagih %.2f%% = %.2f%% melebihi progress fisik (%.2f%%). Update progress dulu.',
                    $terminPct, $project->tertagih_pct, $newTertagih, $project->progress_pct,
                ),
            ]);
        }

        $amount = round((float) $project->nilai_kontrak * $terminPct / 100, 2);

        // Validasi vs DP: total penerimaan (DP + tertagih existing + termin baru)
        // tidak boleh melebihi nilai kontrak. Kalau DP sudah full, tertagih baru
        // harus 0 — klien sudah bayar semua di depan.
        $tertagihNilai = round((float) $project->nilai_kontrak * (float) $project->tertagih_pct / 100, 2);
        $totalPenerimaan = $tertagihNilai + $amount + (float) $project->dp_diterima;

        if (round($totalPenerimaan, 2) > round((float) $project->nilai_kontrak, 2) + 0.01) {
            $sisaBisaTermin = max(0, (float) $project->nilai_kontrak - $tertagihNilai - (float) $project->dp_diterima);
            throw ValidationException::withMessages([
                'termin_pct' => sprintf(
                    'Total penerimaan (DP Rp %s + tertagih Rp %s + termin baru Rp %s = Rp %s) melebihi nilai kontrak Rp %s. '
                    . 'Sisa yang bisa ditagih via termin: Rp %s. '
                    . 'Kalau DP sudah full-payment, tidak perlu tagih termin lagi.',
                    number_format((float) $project->dp_diterima, 0, ',', '.'),
                    number_format($tertagihNilai, 0, ',', '.'),
                    number_format($amount, 0, ',', '.'),
                    number_format($totalPenerimaan, 0, ',', '.'),
                    number_format((float) $project->nilai_kontrak, 0, ',', '.'),
                    number_format($sisaBisaTermin, 0, ',', '.'),
                ),
            ]);
        }

        $invDate = $invoiceDate ? Carbon::parse($invoiceDate) : Carbon::today();
        $company = Company::findOrFail($project->company_id);

        $bongUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'BONG')
            ->first();

        $nextTerminNumber = (int) ($project->termins()->max('termin_number') ?? 0) + 1;

        return DB::transaction(function () use (
            $project, $terminPct, $amount, $invDate, $description,
            $bongUnit, $nextTerminNumber
        ) {
            // 1. Bikin invoice draft
            $invoice = Invoice::create([
                'company_id'       => $project->company_id,
                'invoice_number'   => 'DRAFT-' . now()->format('ymdHisu'),
                'invoice_date'     => $invDate,
                'due_date'         => $invDate->copy()->addDays(30),
                'client_id'        => $project->client_id,
                'business_unit_id' => optional($bongUnit)->id,
                'description'      => sprintf(
                    'Termin %d (%.2f%%) — %s %s%s',
                    $nextTerminNumber,
                    $terminPct,
                    $project->project_number,
                    $project->name,
                    $description ? ' — ' . $description : '',
                ),
                'amount'           => $amount,
                'paid_amount'      => 0,
                'status'           => 'draft',
                'source_type'      => 'project_termin',
                'source_id'        => $project->id,
                'created_by'       => Auth::id() ?? $project->created_by,
            ]);

            // 2. Issue invoice
            $invoice = $this->invoiceService->issue($invoice);

            // 3. Save termin record
            ProjectTermin::create([
                'company_id'    => $project->company_id,
                'project_id'    => $project->id,
                'termin_number' => $nextTerminNumber,
                'termin_pct'    => $terminPct,
                'amount'        => $amount,
                'invoice_id'    => $invoice->id,
                'description'   => $description,
                'created_by'    => Auth::id() ?? $project->created_by,
            ]);

            // 4. Update project tertagih_pct
            $project->update([
                'tertagih_pct' => round((float) $project->tertagih_pct + $terminPct, 2),
            ]);

            return $invoice;
        });
    }

    /**
     * Tutup proyek.
     */
    public function selesai(Project $project): Project
    {
        if (! $project->isBerjalan()) {
            throw ValidationException::withMessages([
                'status' => 'Proyek sudah tidak berjalan.',
            ]);
        }

        $project->update([
            'status'   => 'selesai',
            'ended_at' => Carbon::today(),
        ]);

        return $project->refresh();
    }
}
