<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTermin extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'project_id',
        'termin_number',
        'termin_pct',
        'amount',          // nilai BRUTO termin (pct × nilai kontrak)
        'dp_potong',       // potongan DP; invoice = amount − dp_potong
        'invoice_id',
        'dp_journal_id',
        'description',
        'created_by',
    ];

    protected $casts = [
        'termin_number' => 'integer',
        'termin_pct'    => 'decimal:2',
        'amount'        => 'decimal:2',
        'dp_potong'     => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
