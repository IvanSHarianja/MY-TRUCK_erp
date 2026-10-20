<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sparepart yang dipakai pada satu servis (AssetMaintenanceLog).
 *
 * unit_cost = MAC sparepart saat dipakai, dibekukan di baris ini. Jangan baca
 * ulang current_mac saat menampilkan/rollback — MAC master berubah tiap
 * pembelian berikutnya, sedangkan jurnal servis yang sudah terbit tidak.
 */
class AssetMaintenanceLogItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'asset_maintenance_log_id',
        'material_id',
        'qty',
        'unit_cost',
        'total',
    ];

    protected $casts = [
        'qty'       => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'total'     => 'decimal:2',
    ];

    public function maintenanceLog(): BelongsTo
    {
        return $this->belongsTo(AssetMaintenanceLog::class, 'asset_maintenance_log_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
