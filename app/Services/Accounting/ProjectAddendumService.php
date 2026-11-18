<?php

namespace App\Services\Accounting;

use App\Enums\Permission;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectAddendum;
use App\Models\User;
use App\Support\DocumentSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pekerjaan tambah proyek borongan dengan persetujuan Owner.
 *
 *   submit()  — pengajuan (status pending). Pengaju yang berwenang menyetujui
 *               (izin "Setujui Perubahan Kontrak", default Owner) → langsung disetujui.
 *   approve() — invoice sebesar nilai pekerjaan tambah terbit (piutang klien),
 *               bertanggal hari persetujuan. Ditolak bila proyek sudah batal.
 *   reject() / cancel() — tanpa jurnal.
 * Void invoice-nya → status 'void' (InvoiceObserver).
 */
class ProjectAddendumService
{
    public function __construct(private InvoiceService $invoices, private JournalService $journals) {}

    public function canApprove(?User $user, Company $company): bool
    {
        return $user !== null && $user->canIn($company, Permission::ContractApprove);
    }

    public function submit(Project $project, User $user, array $data): ProjectAddendum
    {
        $company = Company::withoutGlobalScopes()->findOrFail($project->company_id);
        if (! $project->isBerjalan()) {
            throw ValidationException::withMessages(['project' => 'Pekerjaan tambah hanya untuk proyek yang masih berjalan.']);
        }
        $nilai = round((float) ($data['nilai'] ?? 0));
        $uraian = trim((string) ($data['uraian'] ?? ''));
        if ($nilai <= 0) {
            throw ValidationException::withMessages(['nilai' => 'Nilai pekerjaan tambah harus lebih dari 0.']);
        }
        if (mb_strlen($uraian) < 5) {
            throw ValidationException::withMessages(['uraian' => 'Jelaskan pekerjaan tambahnya (minimal 5 karakter).']);
        }
        $date = Carbon::parse($data['tanggal'] ?? today());
        $this->journals->assertPeriodOpen($company, $date->year, $date->month);

        return DB::transaction(function () use ($project, $user, $company, $nilai, $uraian, $date) {
            $addendum = DocumentSequence::createWithRetry(fn () => ProjectAddendum::create([
                'company_id'      => $company->id,
                'project_id'      => $project->id,
                'addendum_number' => DocumentSequence::next(
                    ProjectAddendum::withoutGlobalScopes()->where('company_id', $company->id),
                    'addendum_number', "{$project->project_number}-ADD", 2,
                ),
                'tanggal'         => $date->toDateString(),
                'uraian'          => mb_substr($uraian, 0, 255),
                'nilai'           => $nilai,
                'status'          => 'pending',
                'requested_by'    => $user->id,
            ]));

            return $this->canApprove($user, $company)
                ? $this->approve($addendum, $user, 'Diajukan & disetujui langsung oleh pemberi persetujuan.')
                : $addendum;
        });
    }

    public function approve(ProjectAddendum $addendum, User $approver, ?string $note = null): ProjectAddendum
    {
        $company = Company::withoutGlobalScopes()->findOrFail($addendum->company_id);
        if (! $this->canApprove($approver, $company)) {
            throw ValidationException::withMessages(['status' => 'Anda tidak berwenang menyetujui pekerjaan tambah.']);
        }

        return DB::transaction(function () use ($addendum, $approver, $note, $company) {
            $locked = ProjectAddendum::withoutGlobalScopes()->lockForUpdate()->findOrFail($addendum->id);
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => "Pekerjaan tambah {$locked->addendum_number} sudah " . ProjectAddendum::STATUS[$locked->status] . '.']);
            }
            $project = Project::withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->project_id);
            // Proyek bisa berubah status sejak diajukan. Proyek selesai masih boleh
            // (pekerjaan tambah sering ditagih di akhir); proyek batal tidak.
            if ($project->status === 'batal') {
                throw ValidationException::withMessages(['status' => "Proyek {$project->project_number} sudah dibatalkan — pekerjaan tambah tidak bisa disetujui. Tolak pengajuan ini."]);
            }
            $bong = BusinessUnit::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'BONG')->first();
            // Tanggal invoice = tanggal persetujuan (keputusan 2026-11-18): piutang
            // timbul saat Owner menyetujui, dan tidak mundur ke bulan yang sudah ditutup.
            $date = Carbon::today();
            $this->journals->assertPeriodOpen($company, $date->year, $date->month);

            $invoice = Invoice::create([
                'company_id'       => $company->id,
                'invoice_number'   => 'DRAFT-' . now()->format('ymdHisu'),
                'invoice_date'     => $date,
                'due_date'         => $date->copy()->addDays(30),
                'client_id'        => $project->client_id,
                'business_unit_id' => optional($bong)->id,
                'description'      => "Pekerjaan tambah {$locked->addendum_number}: {$locked->uraian} — {$project->project_number} {$project->name}",
                'amount'           => (float) $locked->nilai,
                'paid_amount'      => 0,
                'status'           => 'draft',
                'source_type'      => 'project_addendum',
                'source_id'        => $locked->id,
                'created_by'       => $locked->requested_by ?? $approver->id,
            ]);
            $invoice = $this->invoices->issue($invoice);

            $locked->update([
                'status' => 'approved', 'reviewed_by' => $approver->id, 'reviewed_at' => now(),
                'review_note' => $note, 'invoice_id' => $invoice->id,
            ]);

            return $locked->refresh();
        });
    }

    public function reject(ProjectAddendum $addendum, User $approver, string $note): ProjectAddendum
    {
        $company = Company::withoutGlobalScopes()->findOrFail($addendum->company_id);
        if (! $this->canApprove($approver, $company)) {
            throw ValidationException::withMessages(['status' => 'Anda tidak berwenang menolak pekerjaan tambah.']);
        }
        if (mb_strlen(trim($note)) < 3) {
            throw ValidationException::withMessages(['review_note' => 'Isi alasan penolakan.']);
        }

        return $this->close($addendum, 'rejected', $approver, $note);
    }

    public function cancel(ProjectAddendum $addendum, User $user): ProjectAddendum
    {
        if ($addendum->requested_by !== $user->id) {
            throw ValidationException::withMessages(['status' => 'Hanya pengaju yang bisa membatalkan pengajuan ini.']);
        }

        return $this->close($addendum, 'cancelled', $user, null);
    }

    private function close(ProjectAddendum $addendum, string $status, User $user, ?string $note): ProjectAddendum
    {
        return DB::transaction(function () use ($addendum, $status, $user, $note) {
            $locked = ProjectAddendum::withoutGlobalScopes()->lockForUpdate()->findOrFail($addendum->id);
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => "Pekerjaan tambah {$locked->addendum_number} sudah diproses."]);
            }
            $locked->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $note]);

            return $locked->refresh();
        });
    }
}
