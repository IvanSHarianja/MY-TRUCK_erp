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
        'role',
        'tax_type',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'role'      => \App\Enums\AccountRole::class,
        ];
    }

    /**
     * Ambil semua akun POSTABLE untuk company dengan role tertentu.
     * Dipakai di service layer sebagai pengganti hardcoded code lookup.
     */
    public static function byRole(\App\Enums\AccountRole|string $role, int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        $roleValue = $role instanceof \App\Enums\AccountRole ? $role->value : $role;

        return static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('role', $roleValue)
            ->where('is_active', true)
            ->postable()
            ->orderBy('code')
            ->get();
    }

    /**
     * Ambil akun postable pertama untuk role tertentu.
     * Return null kalau tidak ada — caller wajib defensive handle.
     */
    public static function firstByRole(\App\Enums\AccountRole|string $role, int $companyId): ?self
    {
        return static::byRole($role, $companyId)->first();
    }

    /**
     * Cari akun POSTABLE dengan prioritas: role first, fallback ke code.
     *
     * Dipakai service refactor Sprint 2.5 untuk transisi smooth:
     *  - Data baru & template standar: role sudah di-set → return by role.
     *  - Data lama (belum di-migrate) yang pakai code standar → fallback ke code.
     *  - Akun custom tanpa role & bukan code standar → NULL (caller error jelas).
     *
     * Setelah semua tenant produksi selesai backfill role, fallback bisa
     * di-remove di rilis berikutnya.
     */
    public static function findByRoleOrCode(
        \App\Enums\AccountRole|string $role,
        string $fallbackCode,
        int $companyId,
    ): ?self {
        $byRole = static::firstByRole($role, $companyId);

        if ($byRole) {
            return $byRole;
        }

        // Fallback code lookup (backward compat, untuk data legacy).
        return static::findPostableByCode($fallbackCode, $companyId);
    }

    /**
     * Descendant IDs berbasis role — pengganti descendantIds(code) di
     * service CashFlow yang perlu semua sub-akun kas/bank.
     *
     * Return array ID semua akun yang punya role tertentu (postable),
     * termasuk semua descendant kalau ada.
     *
     * @return array<int>
     */
    public static function idsByRole(\App\Enums\AccountRole|string $role, int $companyId): array
    {
        return static::byRole($role, $companyId)->pluck('id')->all();
    }

    protected static function booted(): void
    {
        // === Auto-fill sub_category & cash_flow_category (Sprint 2.5+) ===
        //
        // Kenapa: user sering lupa isi sub_category / cash_flow_category saat
        // bikin akun custom. Filter Neraca (sub_category) dan Arus Kas
        // (cash_flow_category) jadi tidak lolos → laporan salah diam-diam.
        //
        // Solusi: auto-derive dari role (priority 1) atau category (priority 2).
        // Explicit user set tetap dihormati (cuma fill kalau kosong).
        static::saving(function (Account $account) {
            // Priority 1: role di-set → derive persis
            if ($account->role) {
                $role = $account->role instanceof \App\Enums\AccountRole
                    ? $account->role
                    : \App\Enums\AccountRole::tryFrom((string) $account->role);

                if ($role) {
                    if (empty($account->sub_category)) {
                        $account->sub_category = $role->defaultSubCategory();
                    }
                    if (empty($account->cash_flow_category)) {
                        $account->cash_flow_category = $role->defaultCashFlow();
                    }
                }
            }

            // Priority 2: role kosong tapi category ada → default per category
            if (empty($account->sub_category) && $account->category) {
                $account->sub_category = match ($account->category) {
                    'aset'       => 'aset_lancar',        // asumsi konservatif — mayoritas aset UMKM lancar
                    'kewajiban'  => 'kewajiban_lancar',   // mayoritas utang jangka pendek
                    'ekuitas'    => 'ekuitas',
                    'pendapatan' => 'pendapatan_usaha',   // pendapatan utama
                    'beban'      => 'beban_operasional',  // beban tidak spesifik → operasional
                    'penutup'    => 'penutup',
                    default      => null,
                };
            }

            if (empty($account->cash_flow_category) && $account->category) {
                $account->cash_flow_category = match ($account->category) {
                    'aset', 'kewajiban', 'pendapatan', 'beban' => 'operasi',
                    'ekuitas'                                    => 'pendanaan',
                    'penutup'                                    => 'non_kas',
                    default                                       => null,
                };
            }
        });

        // === Validasi saat save: cek self-reference & cyclic reference ===
        static::saving(function (Account $account) {
            if (! $account->parent_code) return;

            // Self-reference: parent_code tidak boleh = code sendiri
            if ($account->parent_code === $account->code) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'parent_code' => "Akun tidak boleh menjadi parent dari dirinya sendiri ({$account->code}).",
                ]);
            }

            // Cyclic check: parent_code yang dipilih tidak boleh ada di descendant akun ini
            // (mencegah loop: A→B→A)
            if ($account->exists && $account->code) {
                $descendants = static::descendantIds($account->code, $account->company_id, includeSelf: false);
                $parentRecord = static::withoutGlobalScopes()
                    ->where('company_id', $account->company_id)
                    ->where('code', $account->parent_code)
                    ->first();

                if ($parentRecord && in_array($parentRecord->id, $descendants)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'parent_code' => "Cyclic reference: akun [{$account->parent_code}] adalah sub-akun dari [{$account->code}]. "
                            . "Tidak boleh dijadikan parent.",
                    ]);
                }
            }
        });

        // === Proteksi delete ===
        static::deleting(function (Account $account) {
            // Block jika punya children (orphan prevention)
            $childrenCount = static::withoutGlobalScopes()
                ->where('company_id', $account->company_id)
                ->where('parent_code', $account->code)
                ->count();

            if ($childrenCount > 0) {
                throw new \RuntimeException(
                    "Akun [{$account->code}] {$account->name} adalah HEADER dengan {$childrenCount} sub-akun. "
                    . "Hapus semua sub-akun terlebih dahulu sebelum menghapus akun ini."
                );
            }

            // Block jika sudah dipakai di journal entry
            $usedInJournal = \App\Models\JournalEntryLine::where('account_id', $account->id)->exists();
            if ($usedInJournal) {
                throw new \RuntimeException(
                    "Akun [{$account->code}] {$account->name} sudah memiliki transaksi jurnal. "
                    . "Tidak bisa dihapus untuk menjaga integritas data akuntansi."
                );
            }
        });
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
