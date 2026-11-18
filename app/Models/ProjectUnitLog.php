<?php

namespace App\Models;

use App\Enums\AccountRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pemakaian unit sendiri (alat berat / truk) di Proyek Borongan, per hari.
 * Dibuat & diubah HANYA lewat ProjectUnitLogService (jurnal + penyusutan).
 */
class ProjectUnitLog extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    /** Komponen biaya → [label, Peran akun beban, kode standar]. */
    public const COSTS = [
        'biaya_bbm'  => ['BBM', AccountRole::CogsBbm, '551100'],
        'gaji'       => ['Gaji operator/supir', AccountRole::OpexGaji, '552200'],
        'uang_makan' => ['Uang makan', AccountRole::CogsPremiUangJalan, '551200'],
        'premi'      => ['Premi / uang jalan', AccountRole::CogsPremiUangJalan, '551200'],
        'biaya_lain' => ['Lain-lain', AccountRole::OpexLain, '553000'],
    ];

    protected $fillable = [
        'company_id', 'project_id', 'asset_id', 'operator_id', 'log_date',
        'jam_kerja', 'rit_count', 'solar_liter', 'harga_solar', 'biaya_bbm',
        'gaji', 'uang_makan', 'premi', 'biaya_lain', 'biaya_lain_ket', 'total_biaya',
        'cash_account_id', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'log_date'    => 'date',
        'jam_kerja'   => 'decimal:2',
        'rit_count'   => 'integer',
        'solar_liter' => 'decimal:2',
        'harga_solar' => 'integer',
        'biaya_bbm'   => 'integer',
        'gaji'        => 'integer',
        'uang_makan'  => 'integer',
        'premi'       => 'integer',
        'biaya_lain'  => 'integer',
        'total_biaya' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['log_date', 'asset_id', 'jam_kerja', 'rit_count', 'total_biaya'])
            ->logOnlyDirty()
            ->useLogName('project_unit_log');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'operator_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** "8 jam" / "12 rit" / "8 jam · 3 rit" */
    public function usageLabel(): string
    {
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');

        return implode(' · ', array_filter([
            (float) $this->jam_kerja > 0 ? $num($this->jam_kerja) . ' jam' : null,
            (int) $this->rit_count > 0 ? $this->rit_count . ' rit' : null,
        ])) ?: '—';
    }
}
