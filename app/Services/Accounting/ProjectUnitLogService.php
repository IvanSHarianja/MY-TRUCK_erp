<?php

namespace App\Services\Accounting;

use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectUnitLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemakaian unit sendiri di Proyek Borongan (log harian).
 *
 * Per log:
 *   1. Jurnal biaya "BKK-PU-{id}": Dr BBM / Gaji / Uang makan / Premi / Lain
 *      (sesuai Peran akun) / Cr Kas — lini BONG, ditandai unit, source = log.
 *   2. Penyusutan pemakaian "DEPUSE-{aset}-PU{id}" bila metode aset per jam /
 *      per rit (jam_kerja / rit_count). Aset garis lurus & per hari disusutkan
 *      bulanan oleh DepreciationService (tidak dialokasikan ke proyek).
 * Ubah log = batalkan (void) kedua jurnal lalu posting ulang. Hapus = void.
 * Tanggal di periode tertutup ditolak di depan.
 */
class ProjectUnitLogService
{
    public function __construct(
        private JournalService $journals,
        private DepreciationService $depreciation,
        private OperationalCostService $costs,
    ) {}

    public function create(Project $project, array $data): ProjectUnitLog
    {
        return DB::transaction(function () use ($project, $data) {
            $attrs = $this->normalize($project, $data);
            $log = ProjectUnitLog::create($attrs + [
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'created_by' => Auth::id(),
            ]);
            $this->post($log->refresh());

            return $log->refresh();
        });
    }

    public function update(ProjectUnitLog $log, array $data): ProjectUnitLog
    {
        $project = Project::withoutGlobalScopes()->findOrFail($log->project_id);

        return DB::transaction(function () use ($log, $project, $data) {
            // Tanggal LAMA juga harus di periode terbuka: jurnal lamanya di-void,
            // dan void menghapus angka dari periode asalnya.
            $company = Company::withoutGlobalScopes()->findOrFail($log->company_id);
            $this->journals->assertPeriodOpen($company, $log->log_date->year, $log->log_date->month);
            $attrs = $this->normalize($project, $data);
            $this->voidJournals($log, 'Ubah log pemakaian unit');
            $log->update($attrs);
            $this->post($log->refresh());

            return $log->refresh();
        });
    }

    public function delete(ProjectUnitLog $log): void
    {
        DB::transaction(function () use ($log) {
            $company = Company::withoutGlobalScopes()->findOrFail($log->company_id);
            $this->journals->assertPeriodOpen($company, $log->log_date->year, $log->log_date->month);
            $this->voidJournals($log, 'Hapus log pemakaian unit');
            $log->delete();
        });
    }

    /** @return array<string, mixed> kolom log yang sudah dibersihkan */
    private function normalize(Project $project, array $data): array
    {
        $company = Company::withoutGlobalScopes()->findOrFail($project->company_id);
        $date = Carbon::parse($data['log_date'] ?? today());

        if (! $project->isBerjalan()) {
            throw ValidationException::withMessages(['project' => 'Pemakaian unit hanya bisa dicatat di proyek yang masih berjalan.']);
        }
        $this->journals->assertPeriodOpen($company, $date->year, $date->month);

        // Guard tenant di service (form bisa dilewati).
        $asset = Asset::withoutGlobalScopes()->where('company_id', $company->id)->find($data['asset_id'] ?? 0)
            ?? throw ValidationException::withMessages(['asset_id' => 'Unit tidak ditemukan di PT ini.']);
        if ($asset->status === 'non_aktif') {
            throw ValidationException::withMessages(['asset_id' => "Unit {$asset->asset_code} sudah non-aktif (dijual / dipensiunkan)."]);
        }
        $operatorId = null;
        if (! empty($data['operator_id'])) {
            $operatorId = Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($data['operator_id'])->value('id')
                ?? throw ValidationException::withMessages(['operator_id' => 'Operator/supir tidak ditemukan di PT ini.']);
        }

        $jam = max(0, round((float) ($data['jam_kerja'] ?? 0), 2));
        $rit = max(0, (int) ($data['rit_count'] ?? 0));
        if ($jam <= 0 && $rit <= 0) {
            throw ValidationException::withMessages(['jam_kerja' => 'Isi jam kerja (alat berat) atau jumlah rit (truk).']);
        }

        $money = fn (string $k) => max(0, round((float) ($data[$k] ?? 0)));
        $liter = max(0, round((float) ($data['solar_liter'] ?? 0), 2));
        $harga = $liter > 0 ? $this->costs->resolveHargaBbm($data['harga_solar'] ?? null, $company) : 0.0;
        $bbm   = round($liter * $harga);

        $attrs = [
            'asset_id'       => $asset->id,
            'operator_id'    => $operatorId,
            'log_date'       => $date->toDateString(),
            'jam_kerja'      => $jam > 0 ? $jam : null,
            'rit_count'      => $rit > 0 ? $rit : null,
            'solar_liter'    => $liter,
            'harga_solar'    => $harga,
            'biaya_bbm'      => $bbm,
            'gaji'           => $money('gaji'),
            'uang_makan'     => $money('uang_makan'),
            'premi'          => $money('premi'),
            'biaya_lain'     => $money('biaya_lain'),
            'biaya_lain_ket' => isset($data['biaya_lain_ket']) ? mb_substr((string) $data['biaya_lain_ket'], 0, 150) : null,
            'notes'          => $data['notes'] ?? null,
        ];
        $attrs['total_biaya'] = $bbm + $attrs['gaji'] + $attrs['uang_makan'] + $attrs['premi'] + $attrs['biaya_lain'];

        $attrs['cash_account_id'] = null;
        if ($attrs['total_biaya'] > 0) {
            $cash = ! empty($data['cash_account_id'])
                ? Account::withoutGlobalScopes()->where('company_id', $company->id)->find($data['cash_account_id'])
                : Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);
            // Harus benar-benar akun kas/bank (form membatasi, service juga — form bisa dilewati).
            if (! $cash || ! $cash->isPostable() || ! in_array((int) $cash->id, array_map('intval', Account::cashAccountIds($company->id)), true)) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih akun kas/bank (sub-akun) untuk membayar biaya.']);
            }
            $attrs['cash_account_id'] = $cash->id;
        }

        return $attrs;
    }

    private function post(ProjectUnitLog $log): void
    {
        $company = Company::withoutGlobalScopes()->findOrFail($log->company_id);
        $project = Project::withoutGlobalScopes()->findOrFail($log->project_id);
        $asset   = Asset::withoutGlobalScopes()->findOrFail($log->asset_id);
        $bong    = BusinessUnit::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'BONG')->first();
        $date    = Carbon::parse($log->log_date);

        if ((float) $log->total_biaya > 0) {
            $lines = [];
            foreach (ProjectUnitLog::COSTS as $field => [$label, $role, $code]) {
                $amount = (float) $log->{$field};
                if ($amount <= 0) {
                    continue;
                }
                $account = Account::findByRoleOrCode($role, $code, $company->id)
                    ?? throw ValidationException::withMessages(['biaya' => "Akun beban \"{$label}\" tidak ditemukan. Beri Peran \"{$role->label()}\" di Daftar Akun."]);
                $lines[] = [
                    'account_id'  => $account->id,
                    'asset_id'    => $asset->id,
                    'description' => "{$label} [{$asset->asset_code}] — {$project->project_number}"
                        . ($field === 'biaya_bbm' ? ' (' . rtrim(rtrim((string) $log->solar_liter, '0'), '.') . ' L)' : '')
                        . ($field === 'biaya_lain' && $log->biaya_lain_ket ? " ({$log->biaya_lain_ket})" : ''),
                    'debit'       => $amount,
                    'kredit'      => 0,
                ];
            }
            $lines[] = [
                'account_id'  => $log->cash_account_id,
                'description' => "Biaya unit {$asset->asset_code} proyek {$project->project_number}",
                'debit'       => 0,
                'kredit'      => (float) $log->total_biaya,
            ];

            $journal = $this->journals->createEntryWithLines(
                company:          $company,
                date:             $date,
                entryDataFactory: fn (string $n): array => [
                    'company_id'       => $company->id,
                    'entry_number'     => $n,
                    'entry_date'       => $date,
                    'document_number'  => "BKK-PU-{$log->id}",
                    'document_type'    => 'bkk',
                    'source_type'      => $log->getMorphClass(),
                    'source_id'        => $log->id,
                    'business_unit_id' => optional($bong)->id,
                    'description'      => "Pemakaian {$asset->asset_code} di proyek {$project->project_number} — {$date->format('d/m/Y')} ({$log->usageLabel()})",
                    'period_year'      => $date->year,
                    'period_month'     => $date->month,
                    'status'           => 'posted',
                    'created_by'       => Auth::id() ?? $log->created_by,
                    'posted_by'        => Auth::id() ?? $log->created_by,
                    'posted_at'        => now(),
                    'total_amount'     => (float) $log->total_biaya,
                ],
                linesFactory: fn () => $lines,
            );
            ProjectUnitLog::withoutEvents(fn () => $log->update(['journal_entry_id' => $journal->id]));
        }

        // Penyusutan pemakaian (aset per jam / rit / hari). Nomor dokumen memuat
        // waktu posting supaya posting ulang setelah ubah log tidak bentrok
        // dengan jurnal lama yang sudah di-void (cek idempoten per nomor).
        // Per HARI tidak di sini: DepreciationService sudah menyusutkannya tiap
        // bulan untuk SEMUA hari kalender (time-based). Dulu log proyek menambah
        // 1 hari lagi per catatan → penyusutan dobel.
        $usage = match ($asset->depreciation_method) {
            DepreciationMethod::PerHour => (float) $log->jam_kerja,
            DepreciationMethod::PerRit  => (float) $log->rit_count,
            default                     => 0.0,
        };
        if ($usage > 0) {
            $version = JournalEntry::withoutGlobalScopes()->fromSource($log)->where('document_number', 'like', 'DEPUSE-%')->count() + 1;
            $this->depreciation->postUsageDepreciation(
                $asset, $usage, $date,
                "DEPUSE-{$asset->id}-PU{$log->id}" . ($version > 1 ? "-{$version}" : ''),
                "proyek {$project->project_number}",
                $log,
            );
        }
    }

    private function voidJournals(ProjectUnitLog $log, string $reason): void
    {
        $journals = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $log->company_id)
            ->fromSource($log)
            ->where('status', 'posted')
            ->get();

        self::$voiding = true;
        try {
            foreach ($journals as $journal) {
                $this->journals->void($journal, $reason);
            }
        } finally {
            self::$voiding = false;
        }
        ProjectUnitLog::withoutEvents(fn () => $log->update(['journal_entry_id' => null]));
    }

    /**
     * True selama service ini me-void jurnalnya sendiri. Void jurnal biaya /
     * penyusutan pemakaian unit dari Jurnal Umum ditolak JournalEntryObserver:
     * log tetap mencatat biayanya → Laporan Margin Proyek tidak cocok dengan buku.
     */
    public static bool $voiding = false;

    /**
     * Biaya pemakaian unit per proyek: kas (log) + penyusutan pemakaian.
     *
     * @return array{biaya_kas: float, penyusutan: float}
     */
    public function projectCosts(Project $project): array
    {
        $logIds = ProjectUnitLog::withoutGlobalScopes()->where('project_id', $project->id)->pluck('id');

        $depuse = $logIds->isEmpty() ? 0.0 : (float) JournalEntry::withoutGlobalScopes()
            ->where('company_id', $project->company_id)
            ->where('source_type', (new ProjectUnitLog())->getMorphClass())
            ->whereIn('source_id', $logIds)
            ->where('document_number', 'like', 'DEPUSE-%')
            ->where('status', 'posted')
            ->sum('total_amount');

        return [
            'biaya_kas'  => (float) ProjectUnitLog::withoutGlobalScopes()->where('project_id', $project->id)->sum('total_biaya'),
            'penyusutan' => round($depuse, 2),
        ];
    }
}
