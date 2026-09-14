<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBuktiTf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToCompany;
    use HasBuktiTf;

    protected $fillable = [
        'company_id',
        'entry_number',
        'entry_date',
        'document_number',
        'document_type',
        'business_unit_id',
        'description',
        'total_amount',
        'period_year',
        'period_month',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by_id',
        'bukti_tf_path',
        'source_type',
        'source_id',
    ];

    /**
     * Format No. Bukti yang hanya boleh dibuat auto-journal sistem.
     *
     * document_number dipakai sebagai kunci idempotensi (DEP-/DEPUSE-) dan
     * dibaca manusia sebagai "jurnal ini milik dokumen X" (INV/PAY/PB/PJ).
     * Jurnal manual yang meniru format ini bisa memblokir auto-post (dianggap
     * sudah ada) atau menyesatkan audit.
     *
     * Pola sengaja presisi supaya No. Bukti manual yang wajar tetap boleh,
     * mis. "PBB-2026" (pajak), "DP-SUPPLIER-01", "BKK-001". Format quick tx
     * (BBK/BKM/JM{YYMM}-NNNN) sengaja TIDAK dicadangkan — itu format bukti kas
     * yang lazim diketik manual dan tidak dipakai sebagai kunci cascade.
     */
    public const SYSTEM_DOCUMENT_PATTERNS = [
        '/^(INV|PAY|PB|PJ)\d{4}-/i',           // invoice, payment, pembelian, penjualan material
        '/^BBK-(RL|RT|MT)-\d+/i',              // biaya operasional per log
        '/^DEP-\d+-/i',                         // penyusutan bulanan DEP-{asset}-{YYYYMM}
        '/^DEPUSE-\d+-/i',                      // penyusutan usage DEPUSE-{asset}-{log}
        '/^DP-PR\d{2}-/i',                      // DP proyek DP-{PR26-0001}
        '/^HPP-PJ\d{4}-/i',                     // HPP penjualan material
        '/^REV-J\d{4}-/i',                      // jurnal pembalik
    ];

    public static function isSystemDocumentNumber(?string $documentNumber): bool
    {
        $documentNumber = trim((string) $documentNumber);
        if ($documentNumber === '') {
            return false;
        }

        foreach (self::SYSTEM_DOCUMENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $documentNumber) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function casts(): array
    {
        return [
            'entry_date'   => 'date',
            'posted_at'    => 'datetime',
            'total_amount' => 'decimal:2',
            'period_year'  => 'integer',
            'period_month' => 'integer',
            'source_id'    => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order');
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    /**
     * Jurnal yang di-generate sistem untuk dokumen sumber tertentu.
     * source_type/source_id hanya diisi auto-journal — tidak ada di form manual.
     */
    public function scopeFromSource(Builder $query, Model $source): Builder
    {
        return $query
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey());
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function sourceIs(string $modelClass): bool
    {
        return $this->source_id !== null
            && $this->source_type === (new $modelClass)->getMorphClass();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function getTotalDebitAttribute(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function getTotalKreditAttribute(): float
    {
        return (float) $this->lines->sum('kredit');
    }

    public function isBalanced(): bool
    {
        // BUG-18: epsilon tolerance untuk hindari float drift residu
        return abs($this->total_debit - $this->total_kredit) < 0.005;
    }
}
