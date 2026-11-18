<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Pekerjaan tambah proyek — lihat ProjectAddendumService. */
class ProjectAddendum extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    protected $table = 'project_addendums';

    public const STATUS = [
        'pending'   => 'Menunggu Persetujuan',
        'approved'  => 'Disetujui & Ditagih',
        'rejected'  => 'Ditolak',
        'cancelled' => 'Dibatalkan',
        'void'      => 'Invoice Di-void',
    ];

    protected $fillable = [
        'company_id', 'project_id', 'addendum_number', 'tanggal', 'uraian', 'nilai', 'status',
        'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'invoice_id',
    ];

    protected $casts = [
        'tanggal'     => 'date',
        'reviewed_at' => 'datetime',
        'nilai'       => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['addendum_number', 'uraian', 'nilai', 'status', 'review_note', 'invoice_id'])
            ->logOnlyDirty()
            ->useLogName('project_addendum');
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function isPending(): bool { return $this->status === 'pending'; }
}
