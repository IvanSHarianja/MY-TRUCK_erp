<?php

namespace App\Models;

use App\Enums\MaterialJenis;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'jenis',
        'harga_per_satuan',
        'harga_pokok',
        'current_stock',
        'current_mac',
        'satuan',
        'notes',
        'is_active',
    ];

    // Integer cast untuk uang — cegah bug rupiah 100× lipat pada form ->rupiah().
    // current_stock/current_mac decimal karena bisa fractional.
    protected $casts = [
        'jenis'            => MaterialJenis::class,
        'harga_per_satuan' => 'integer',
        'harga_pokok'      => 'integer',
        'current_stock'    => 'decimal:2',
        'current_mac'      => 'decimal:4',
        'is_active'        => 'boolean',
    ];

    public function getDisplayNameAttribute(): string
    {
        return "[{$this->code}] {$this->name}";
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(MaterialPurchase::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(MaterialStockMovement::class);
    }

    public function isSparepart(): bool
    {
        return $this->jenis === MaterialJenis::Sparepart;
    }

    /** Material dagangan — hanya jenis ini yang boleh dijual ke klien. */
    public function scopeJual(Builder $query): Builder
    {
        return $query->where('jenis', MaterialJenis::Jual->value);
    }

    /** Sparepart — hanya jenis ini yang muncul di form pemeliharaan. */
    public function scopeSparepart(Builder $query): Builder
    {
        return $query->where('jenis', MaterialJenis::Sparepart->value);
    }
}
