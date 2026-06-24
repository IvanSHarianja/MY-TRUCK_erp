<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'parent_code',
        'name',
        'category',
        'sub_category',
        'normal_balance',
        'cash_flow_category',
        'tax_type',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Relasi parent (akun induk) via parent_code */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_code', 'code')
            ->where('accounts.company_id', $this->company_id ?? 0);
    }

    /** Relasi children (sub-akun) — akun yang parent_code = code akun ini */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_code', 'code')
            ->where('accounts.company_id', $this->company_id ?? 0);
    }

    /**
     * Apakah akun ini adalah HEADER (punya minimal 1 child)?
     * Header tidak boleh di-post langsung di jurnal.
     */
    public function isHeader(): bool
    {
        return static::query()
            ->where('company_id', $this->company_id)
            ->where('parent_code', $this->code)
            ->exists();
    }

    /**
     * Apakah akun ini POSTABLE (bisa dipakai di jurnal)?
     * Postable = tidak punya children (akun leaf).
     */
    public function isPostable(): bool
    {
        return ! $this->isHeader();
    }

    /**
     * Scope: hanya akun postable (leaf — tidak punya child).
     * Dipakai di dropdown form jurnal supaya parent/header tidak muncul.
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->select(\DB::raw(1))
                ->from('accounts as children')
                ->whereColumn('children.parent_code', 'accounts.code')
                ->whereColumn('children.company_id', 'accounts.company_id');
        });
    }

    /** Scope: hanya akun header (punya children) */
    public function scopeHeaders(Builder $query): Builder
    {
        return $query->whereExists(function ($sub) {
            $sub->select(\DB::raw(1))
                ->from('accounts as children')
                ->whereColumn('children.parent_code', 'accounts.code')
                ->whereColumn('children.company_id', 'accounts.company_id');
        });
    }

    public function getDisplayNameAttribute(): string
    {
        return "[{$this->code}] {$this->name}";
    }

    /**
     * Cari akun POSTABLE berdasarkan kode. Kalau akun dengan kode ini sudah HEADER,
     * fallback ke first child (yang aktif).
     *
     * Dipakai oleh service-service yang punya default akun (Invoice, MaterialSale, dll)
     * supaya tetap berfungsi setelah parent di-split jadi sub-akun.
     */
    public static function findPostableByCode(string $code, int $companyId): ?self
    {
        $account = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) return null;

        // Kalau leaf (postable), pakai langsung
        if (! $account->isHeader()) {
            return $account;
        }

        // Kalau HEADER, ambil first child yang aktif & postable
        return static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('parent_code', $code)
            ->where('is_active', true)
            ->postable()
            ->orderBy('code')
            ->first();
    }

    /**
     * Return semua descendant IDs (children + grandchildren) untuk roll-up.
     * Dipakai CashFlowService untuk include semua sub-akun kas/bank.
     */
    public static function descendantIds(string $parentCode, int $companyId, bool $includeSelf = true): array
    {
        $ids = [];

        if ($includeSelf) {
            $self = static::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', $parentCode)
                ->value('id');
            if ($self) $ids[] = $self;
        }

        // BFS through children
        $codesToProcess = [$parentCode];
        while (! empty($codesToProcess)) {
            $children = static::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('parent_code', $codesToProcess)
                ->get(['id', 'code']);

            if ($children->isEmpty()) break;

            foreach ($children as $child) {
                $ids[] = $child->id;
            }

            $codesToProcess = $children->pluck('code')->all();
        }

        return array_unique($ids);
    }

    /**
     * Generate suggested kode child berikutnya dari parent.
     * Pola: parent code + suffix increment (e.g. 111100 → 111101, 111102, ...)
     */
    public static function suggestChildCode(string $parentCode, int $companyId): string
    {
        $existing = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('parent_code', $parentCode)
            ->orderByDesc('code')
            ->value('code');

        if (! $existing) {
            // Child pertama: parent_code + "01"
            // Misal 111100 → 111101 (sebenarnya replace digit terakhir)
            // Atau: append suffix "-01"
            return $parentCode . '-01';
        }

        // Increment dari child terakhir
        if (preg_match('/-(\d+)$/', $existing, $m)) {
            $next = (int) $m[1] + 1;
            return preg_replace('/-\d+$/', '-' . str_pad((string) $next, strlen($m[1]), '0', STR_PAD_LEFT), $existing);
        }

        // Fallback: append -01
        return $existing . '-01';
    }
}
