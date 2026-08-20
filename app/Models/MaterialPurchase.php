<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBuktiTf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialPurchase extends Model
{
    use BelongsToCompany;
    use HasBuktiTf;

    protected $fillable = [
        'company_id',
        'purchase_number',
        'purchase_date',
        'vendor_id',
        'material_id',
        'qty',
        'unit_price',
        'total_amount',
        'payment_method',
        'cash_account_id',
        'journal_entry_id',
        'created_by',
        'notes',
        'bukti_tf_path',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'qty'           => 'decimal:2',
        'unit_price'    => 'integer',
        'total_amount'  => 'integer',
    ];

    /**
     * BIZ-01 cascade guard: purchase yang sudah punya stock movement TIDAK
     * boleh langsung di-delete. Untuk membatalkan, user harus void jurnal
     * PB-* dulu (via halaman Jurnal Umum) — observer akan cascade rollback
     * stock + MAC + record adjustment movement.
     *
     * Kalau delete langsung diizinkan, stock movement bakal point ke
     * source_id yang non-existent → laporan stok bermasalah.
     */
    protected static function booted(): void
    {
        static::deleting(function (MaterialPurchase $purchase) {
            $hasMovements = MaterialStockMovement::withoutGlobalScopes()
                ->where('source_type', self::class)
                ->where('source_id', $purchase->id)
                ->exists();

            if ($hasMovements) {
                throw new \RuntimeException(sprintf(
                    'Pembelian %s tidak bisa langsung dihapus — sudah tercatat di stock movement. '
                    . 'Untuk membatalkan: buka Operasional → Jurnal Umum, cari nomor %s, '
                    . 'klik Void. Sistem otomatis rollback stok + MAC.',
                    $purchase->purchase_number,
                    $purchase->purchase_number,
                ));
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(MaterialStockMovement::class, 'source_id')
            ->where('source_type', self::class);
    }
}
