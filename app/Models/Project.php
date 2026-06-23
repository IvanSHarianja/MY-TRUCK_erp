<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Project extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_number', 'name', 'nilai_kontrak', 'progress_pct', 'tertagih_pct', 'dp_diterima', 'status'])
            ->logOnlyDirty()
            ->useLogName('project');
    }

    protected $fillable = [
        'company_id',
        'project_number',
        'name',
        'client_id',
        'jenis_pekerjaan',
        'nilai_kontrak',
        'progress_pct',
        'tertagih_pct',
        'dp_diterima',
        'status',
        'started_at',
        'target_end_date',
        'ended_at',
        'description',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'nilai_kontrak'   => 'decimal:2',
        'progress_pct'    => 'decimal:2',
        'tertagih_pct'    => 'decimal:2',
        'dp_diterima'     => 'decimal:2',
        'started_at'      => 'date',
        'target_end_date' => 'date',
        'ended_at'        => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(ProjectProgress::class)->orderByDesc('update_date');
    }

    public function termins(): HasMany
    {
        return $this->hasMany(ProjectTermin::class)->orderBy('termin_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sisa % yang belum ditagih = progress - tertagih */
    public function getSisaTagihPctAttribute(): float
    {
        return max(0, (float) $this->progress_pct - (float) $this->tertagih_pct);
    }

    /** Nilai termin yang sudah ditagih */
    public function getTertagihNilaiAttribute(): float
    {
        return round((float) $this->nilai_kontrak * (float) $this->tertagih_pct / 100, 2);
    }

    /** Sisa nilai kontrak yang belum ditagih */
    public function getSisaNilaiAttribute(): float
    {
        return round((float) $this->nilai_kontrak - $this->tertagih_nilai, 2);
    }

    public function isBerjalan(): bool { return $this->status === 'berjalan'; }
    public function isSelesai(): bool  { return $this->status === 'selesai'; }

    protected static function booted(): void
    {
        static::deleting(function (Project $project) {
            if ((float) $project->tertagih_pct > 0 || (float) $project->dp_diterima > 0) {
                throw new \RuntimeException(
                    "Proyek {$project->project_number} sudah ada termin/DP. Void invoice & jurnal DP terkait dulu."
                );
            }
            if ($project->termins()->exists()) {
                throw new \RuntimeException(
                    "Proyek {$project->project_number} masih memiliki termin. Void invoice termin dulu."
                );
            }
        });
    }
}
