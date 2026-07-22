<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AccountMapping — mapping antara jenis transaksi ke akun COA spesifik per tenant.
 *
 * Contoh:
 *  - transaction_type='setoran_modal' → account_id=id akun 331100 (atau custom)
 *  - transaction_type='penarikan_kas' → account_id=id akun 111100
 *
 * Berbeda dengan AccountRole enum (Sprint 2.5) yang universal fungsional,
 * AccountMapping adalah pilihan user per company: role bisa banyak akun,
 * mapping menunjuk 1 akun spesifik untuk 1 tipe transaksi.
 */
class AccountMapping extends Model
{
    // Trait BelongsToCompany menyediakan:
    //  - method company() : BelongsTo (dipakai Filament tenant scope)
    //  - global scope filter query per Filament tenant
    //  - auto-set company_id saat creating
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'transaction_type',
        'account_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
