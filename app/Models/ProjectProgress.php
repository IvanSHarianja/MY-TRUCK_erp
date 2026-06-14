<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProgress extends Model
{
    use BelongsToCompany;

    protected $table = 'project_progress_updates';

    protected $fillable = [
        'company_id',
        'project_id',
        'update_date',
        'progress_pct',
        'notes',
        'photo_url',
        'created_by',
    ];

    protected $casts = [
        'update_date'  => 'date',
        'progress_pct' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
