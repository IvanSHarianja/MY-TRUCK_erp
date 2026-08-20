<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit log setiap pergerakan stok material.
 * Immutable — sekali di-create, tidak boleh di-edit (safety audit).
 */
class MaterialStockMovement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'material_id',
        'movement_type',
        'source_type',
        'source_id',
        'qty_change',
        'unit_cost',
        'stock_before',
        'stock_after',
        'mac_before',
        'mac_after',
        'movement_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'qty_change'    => 'decimal:2',
        'unit_cost'     => 'decimal:2',
        'stock_before'  => 'decimal:2',
        'stock_after'   => 'decimal:2',
        'mac_before'    => 'decimal:4',
        'mac_after'     => 'decimal:4',
        'movement_date' => 'date',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
