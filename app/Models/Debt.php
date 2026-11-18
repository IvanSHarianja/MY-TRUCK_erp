<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Hutang / pinjaman perusahaan + jadwal cicilan — lihat DebtService. */
class Debt extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public const JENIS = [
        'bank'    => 'Pinjaman Bank',
        'leasing' => 'Leasing',
        'vendor'  => 'Hutang Vendor',
        'lainnya' => 'Lainnya',
    ];

    public const STATUS = ['aktif' => 'Berjalan', 'lunas' => 'Lunas'];

    protected $fillable = [
        'company_id', 'debt_number', 'jenis', 'kreditur', 'vendor_id', 'payable_account_id',
        'pokok', 'bunga_pct', 'tenor_bulan', 'tanggal_mulai', 'jatuh_tempo_pertama', 'status',
        'disbursement_journal_id', 'source_type', 'source_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'pokok'               => 'integer',
        'bunga_pct'           => 'float',
        'tenor_bulan'         => 'integer',
        'tanggal_mulai'       => 'date',
        'jatuh_tempo_pertama' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['debt_number', 'kreditur', 'pokok', 'bunga_pct', 'tenor_bulan', 'status'])
            ->logOnlyDirty()
            ->useLogName('debt');
    }

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function payableAccount(): BelongsTo { return $this->belongsTo(Account::class, 'payable_account_id'); }
    public function installments(): HasMany { return $this->hasMany(DebtInstallment::class)->orderBy('ke'); }

    /** Cicilan belum lunas paling awal. */
    public function nextInstallment(): HasOne
    {
        return $this->hasOne(DebtInstallment::class)->ofMany(['due_date' => 'min', 'id' => 'min'], fn ($q) => $q->where('status', 'belum'));
    }

    public function isLunas(): bool { return $this->status === 'lunas'; }
}
