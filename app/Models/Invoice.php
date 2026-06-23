<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Invoice extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_number', 'invoice_date', 'client_id', 'amount', 'paid_amount', 'status', 'void_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('invoice');
    }

    protected $fillable = [
        'company_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'client_id',
        'business_unit_id',
        'revenue_account_id',
        'receivable_account_id',
        'description',
        'amount',
        'paid_amount',
        'status',
        'source_type',
        'source_id',
        'journal_entry_id',
        'created_by',
        'voided_by',
        'voided_at',
        'void_reason',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'voided_at'    => 'datetime',
        'amount'       => 'decimal:2',
        'paid_amount'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Proteksi hapus: hanya draft atau void yang boleh dihapus,
        // dan tidak boleh ada payment terkait.
        static::deleting(function (Invoice $invoice) {
            if (! in_array($invoice->status, ['draft', 'void'], true)) {
                throw new \RuntimeException(
                    "Invoice {$invoice->invoice_number} berstatus {$invoice->status} — tidak bisa dihapus. Void dulu invoice ini."
                );
            }
            if ($invoice->payments()->exists()) {
                throw new \RuntimeException(
                    "Invoice {$invoice->invoice_number} sudah memiliki pembayaran. Batalkan pembayaran terlebih dahulu."
                );
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    // === Status helpers ===
    public function isDraft(): bool    { return $this->status === 'draft'; }
    public function isTerbit(): bool   { return $this->status === 'terbit'; }
    public function isSebagian(): bool { return $this->status === 'sebagian'; }
    public function isLunas(): bool    { return $this->status === 'lunas'; }
    public function isVoid(): bool     { return $this->status === 'void'; }

    /** Apakah masih bisa diterima pembayaran */
    public function canReceivePayment(): bool
    {
        return in_array($this->status, ['terbit', 'sebagian']);
    }

    /** Sisa yang belum dibayar */
    public function getSisaAttribute(): float
    {
        return (float) $this->amount - (float) $this->paid_amount;
    }

    /** Umur invoice dalam hari (dari tanggal invoice ke hari ini) */
    public function getUmurHariAttribute(): int
    {
        if ($this->isLunas() || $this->isVoid()) {
            return 0;
        }
        return max(0, (int) $this->invoice_date->diffInDays(Carbon::today(), false));
    }

    /** Kategori aging: lancar / perhatian / overdue */
    public function getAgingCategoryAttribute(): string
    {
        $umur = $this->umur_hari;
        if ($umur > 30) return 'overdue';
        if ($umur > 14) return 'perhatian';
        return 'lancar';
    }
}
