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
use App\Support\DocumentSequence;
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

        return DocumentSequence::next(
            Project::withoutGlobalScopes()->where('company_id', $company->id),
            'project_number',
            $prefix,
            4,
        );
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

            // Progress 100% BOLEH dicapai kapan saja — mengikuti realita
            // konstruksi (fisik selesai duluan, tagihan final belakangan).
            // Status hanya auto-transisi ke 'selesai' via syncProjectStatus()
            // ketika progress DAN tertagih_pct sama-sama sudah 100%.
            $project->update(['progress_pct' => $progressPct]);
            $this->syncProjectStatus($project->refresh());

            return $history;
        });
    }

    /**
     * Sinkronkan status proyek dengan progress_pct + tertagih_pct.
     *
     * Rule: status = 'selesai' HANYA jika progress_pct ≥ 100 AND tertagih_pct ≥ 100.
     * Dua kondisi = dua sisi realita: fisik selesai (progress) + kontraktual selesai
     * (tertagih penuh). Status baru transisi kalau keduanya terpenuhi.
     *
     * Dipanggil dari:
     *  - updateProgress() setelah progress naik (kasus: 100% dulu, baru tagih final)
     *  - tagihTermin()   setelah tertagih naik  (kasus: tagih dulu, progress 100% belakangan)
     *
     * Idempotent: kalau status sudah 'selesai' atau syarat belum terpenuhi, no-op.
     */
    private function syncProjectStatus(Project $project): void
    {
        if (! $project->isBerjalan()) {
            return; // sudah 'selesai' / 'dibatalkan' — tidak transisi ulang
        }

        $progressDone  = (float) $project->progress_pct  >= 100 - 0.005;
        $tertagihDone  = (float) $project->tertagih_pct  >= 100 - 0.005;

        if ($progressDone && $tertagihDone) {
            Project::withoutGlobalScopes()
                ->where('id', $project->id)
                ->update([
                    'status'     => 'selesai',
                    'ended_at'   => Carbon::today(),
                    'updated_at' => now(),
                ]);
        }
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
        ?string $buktiTfPath = null,
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
        // DP yang sudah dipotongkan ke termin sudah termasuk dalam tertagih —
        // hanya SISA DP yang dihitung, kalau tidak DP baru ditolak keliru.
        $tertagihNilai = round((float) $project->nilai_kontrak * (float) $project->tertagih_pct / 100, 2);
        $sisaNilai     = round((float) $project->nilai_kontrak - $tertagihNilai - ((float) $project->dp_diterima - (float) $project->dp_dipotong), 2);

        // BUG-20: epsilon +0.005 konsisten dengan tagihTermin
        if (round($amount, 2) > $sisaNilai + 0.005) {
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
            $project, $cashAccount, $amount, $dpDate, $notes, $company, $uangMuka, $bongUnit, $buktiTfPath
        ) {
            // BUG-09: Lock project + re-hitung validasi setelah lock.
            // Cegah 2 request terimaDP() bareng yang lolos validasi awal
            // tapi bikin total DP > nilai kontrak.
            $lockedProject = Project::withoutGlobalScopes()
                ->where('id', $project->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedProject) {
                throw ValidationException::withMessages([
                    'project' => "Proyek tidak ditemukan (id: {$project->id}).",
                ]);
            }

            $tertagihNilaiLocked = round((float) $lockedProject->nilai_kontrak * (float) $lockedProject->tertagih_pct / 100, 2);
            $sisaNilaiLocked = round((float) $lockedProject->nilai_kontrak - $tertagihNilaiLocked
                - ((float) $lockedProject->dp_diterima - (float) $lockedProject->dp_dipotong), 2);

            if (round($amount, 2) > $sisaNilaiLocked) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'DP Rp %s melebihi sisa nilai kontrak (Rp %s) setelah cek concurrent.',
                        number_format($amount, 0, ',', '.'),
                        number_format(max(0, $sisaNilaiLocked), 0, ',', '.'),
                    ),
                ]);
            }

            $project = $lockedProject; // Ganti reference ke locked version

            // BUG-11: Refactor pakai createEntryWithLines
            $journal = $this->journalService->createEntryWithLines(
                company:          $company,
                date:             $dpDate,
                entryDataFactory: fn (string $entryNumber): array => [
                    'company_id'       => $project->company_id,
                    'entry_number'     => $entryNumber,
                    'entry_date'       => $dpDate,
                    'document_number'  => 'DP-' . $project->project_number,
                    'document_type'    => 'bkm',
                    // Link struktural untuk cascade void (rollback dp_diterima) —
                    // No. Bukti bisa diketik bebas di jurnal manual, source tidak.
                    'source_type'      => $project->getMorphClass(),
                    'source_id'        => $project->id,
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
                    'bukti_tf_path'    => $buktiTfPath,
                ],
                linesFactory:     fn (JournalEntry $entry): array => [
                    [
                        'account_id'  => $cashAccount->id,
                        'description' => 'Terima DP ' . $project->project_number,
                        'debit'       => $amount,
                        'kredit'      => 0,
                    ],
                    [
                        'account_id'  => $uangMuka->id,
                        'description' => 'Uang muka diterima dari ' . optional($project->client)->name,
                        'debit'       => 0,
                        'kredit'      => $amount,
                    ],
                ],
            );

            // BUG-09: Atomic increment (bukan read-modify-write).
            // MySQL: SET dp_diterima = dp_diterima + $amount — race-safe.
            Project::withoutGlobalScopes()
                ->where('id', $project->id)
                ->increment('dp_diterima', $amount);

            return $journal;
        });
    }

    /**
     * Tagih termin proyek.
     *
     * DP dipotong PROPORSIONAL (keputusan 2026-11-17):
     *   bruto      = termin% × nilai kontrak               → Pendapatan
     *   potong DP  = termin% × DP diterima (maks. sisa DP); termin yang membuat
     *                tertagih 100% memotong SELURUH sisa DP (menuntaskan proyek
     *                lama yang termin awalnya belum memotong DP)
     *   invoice    = bruto − potong DP                      → Piutang
     * Jurnal: invoice (Dr Piutang / Cr Pendapatan, bersih) + jurnal pemotongan
     * DP (Dr Uang Muka Proyek / Cr Pendapatan, sebesar potongan).
     *
     * Validasi: termin_pct > 0; tertagih + termin ≤ 100; tertagih + termin ≤
     * progress fisik. SEMUA dicek ulang setelah lock — dua klik bersamaan dulu
     * bisa lolos bersama dan membuat tertagih 120%.
     */
    public function tagihTermin(
        Project $project,
        float $terminPct,
        ?CarbonInterface $invoiceDate = null,
        ?string $description = null,
    ): Invoice {
        if ($terminPct <= 0 || $terminPct > 100) {
            throw ValidationException::withMessages([
                'termin_pct' => 'Persen termin harus antara 0.01% sampai 100%.',
            ]);
        }

        $invDate = $invoiceDate ? Carbon::parse($invoiceDate) : Carbon::today();
        $company = Company::findOrFail($project->company_id);

        $bongUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'BONG')
            ->first();

        return DB::transaction(function () use ($project, $terminPct, $invDate, $description, $bongUnit, $company) {
            $locked = Project::withoutGlobalScopes()
                ->where('id', $project->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw ValidationException::withMessages([
                    'project' => "Proyek tidak ditemukan (id: {$project->id}).",
                ]);
            }
            $project = $locked;

            $this->assertTerminAllowed($project, $terminPct);

            $gross    = round((float) $project->nilai_kontrak * $terminPct / 100, 2);
            $dpPotong = $this->dpDeduction($project, $terminPct);
            $net      = round($gross - $dpPotong, 2);

            $nextTerminNumber = (int) (ProjectTermin::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->max('termin_number') ?? 0) + 1;

            $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
            $invoiceDescription = sprintf(
                'Termin %d (%s%%) — %s %s%s%s',
                $nextTerminNumber,
                rtrim(rtrim(number_format($terminPct, 2, ',', '.'), '0'), ','),
                $project->project_number,
                $project->name,
                $dpPotong > 0 ? " — nilai termin {$rp($gross)} dikurangi uang muka {$rp($dpPotong)}" : '',
                $description ? ' — ' . $description : '',
            );

            // 1. Invoice sebesar nilai BERSIH. Bila DP menutup seluruh termin
            //    (bersih 0), tidak ada piutang — cukup jurnal pemotongan DP.
            $invoice = null;
            if ($net > 0.005) {
                $invoice = Invoice::create([
                    'company_id'       => $project->company_id,
                    'invoice_number'   => 'DRAFT-' . now()->format('ymdHisu'),
                    'invoice_date'     => $invDate,
                    'due_date'         => $invDate->copy()->addDays(30),
                    'client_id'        => $project->client_id,
                    'business_unit_id' => optional($bongUnit)->id,
                    'description'      => $invoiceDescription,
                    'amount'           => $net,
                    'paid_amount'      => 0,
                    'status'           => 'draft',
                    'source_type'      => 'project_termin',
                    'source_id'        => $project->id,
                    'created_by'       => Auth::id() ?? $project->created_by,
                ]);
                $invoice = $this->invoiceService->issue($invoice);
            }

            $termin = ProjectTermin::create([
                'company_id'    => $project->company_id,
                'project_id'    => $project->id,
                'termin_number' => $nextTerminNumber,
                'termin_pct'    => $terminPct,
                'amount'        => $gross,
                'dp_potong'     => $dpPotong,
                'invoice_id'    => $invoice?->id,
                'description'   => $description,
                'created_by'    => Auth::id() ?? $project->created_by,
            ]);

            // 2. Jurnal pemotongan DP: kewajiban Uang Muka → Pendapatan.
            if ($dpPotong > 0) {
                $journal = $this->postDpDeduction($company, $project, $termin, $dpPotong, $invDate, $bongUnit);
                $termin->update(['dp_journal_id' => $journal->id]);

                Project::withoutGlobalScopes()->where('id', $project->id)->increment('dp_dipotong', $dpPotong);
            }

            Project::withoutGlobalScopes()
                ->where('id', $project->id)
                ->increment('tertagih_pct', $terminPct);

            $this->syncProjectStatus($project->refresh());

            // Termin tertutup penuh oleh DP tidak punya invoice — kembalikan
            // objek invoice "kosong" yang belum tersimpan supaya pemanggil (UI)
            // tetap bisa menampilkan pesan.
            return $invoice ?? new Invoice([
                'invoice_number' => '—',
                'amount'         => 0,
                'description'    => $invoiceDescription . ' — lunas dari uang muka',
            ]);
        });
    }

    /** Validasi termin terhadap proyek yang SUDAH di-lock. */
    private function assertTerminAllowed(Project $project, float $terminPct): void
    {
        if (! $project->isBerjalan()) {
            throw ValidationException::withMessages([
                'status' => "Hanya proyek BERJALAN yang bisa ditagih (status sekarang: {$project->status}).",
            ]);
        }

        $newTertagih = round((float) $project->tertagih_pct + $terminPct, 2);

        if ($newTertagih > 100.00) {
            throw ValidationException::withMessages([
                'termin_pct' => "Total tertagih akan menjadi {$newTertagih}% — melebihi 100% nilai kontrak.",
            ]);
        }

        if ($newTertagih > round((float) $project->progress_pct, 2) + 0.01) {
            throw ValidationException::withMessages([
                'termin_pct' => sprintf(
                    'Termin %.2f%% + tertagih %.2f%% = %.2f%% melebihi progress fisik (%.2f%%). Update progress dulu.',
                    $terminPct, $project->tertagih_pct, $newTertagih, $project->progress_pct,
                ),
            ]);
        }
    }

    /**
     * Potongan DP untuk termin ini: proporsional, maksimal sisa DP; termin
     * penutup (tertagih jadi 100%) mengambil seluruh sisa DP.
     */
    public function dpDeduction(Project $project, float $terminPct): float
    {
        $sisaDp = round((float) $project->dp_diterima - (float) $project->dp_dipotong, 2);
        if ($sisaDp <= 0) {
            return 0.0;
        }

        if (round((float) $project->tertagih_pct + $terminPct, 2) >= 100.00) {
            return $sisaDp;
        }

        return min($sisaDp, round((float) $project->dp_diterima * $terminPct / 100, 2));
    }

    private function postDpDeduction(Company $company, Project $project, ProjectTermin $termin, float $amount, Carbon $date, ?BusinessUnit $bong): JournalEntry
    {
        $uangMuka = Account::findByRoleOrCode(\App\Enums\AccountRole::UangMukaProyek, '221170', $company->id);
        $revenue  = Account::findByRoleOrCode(\App\Enums\AccountRole::RevenueBong, '441400', $company->id);

        if (! $uangMuka || ! $revenue) {
            throw ValidationException::withMessages([
                'account' => 'Akun Uang Muka Proyek atau Pendapatan Borongan tidak ditemukan/postable — lengkapi Peran akun di Daftar Akun.',
            ]);
        }

        return $this->journalService->createEntryWithLines(
            company:          $company,
            date:             $date,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $date,
                'document_number'  => "DPPOT-{$project->project_number}-T{$termin->termin_number}",
                'document_type'    => 'penyesuaian',
                'source_type'      => $termin->getMorphClass(),
                'source_id'        => $termin->id,
                'business_unit_id' => optional($bong)->id,
                'description'      => "Pemotongan uang muka termin {$termin->termin_number} — {$project->project_number} {$project->name}",
                'period_year'      => $date->year,
                'period_month'     => $date->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $project->created_by,
                'posted_by'        => Auth::id() ?? $project->created_by,
                'posted_at'        => now(),
                'total_amount'     => $amount,
            ],
            linesFactory: fn (JournalEntry $entry): array => [
                ['account_id' => $uangMuka->id, 'description' => "Potong uang muka termin {$termin->termin_number}", 'debit' => $amount, 'kredit' => 0],
                ['account_id' => $revenue->id, 'description' => "Pendapatan termin {$termin->termin_number} dari uang muka", 'debit' => 0, 'kredit' => $amount],
            ],
        );
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
