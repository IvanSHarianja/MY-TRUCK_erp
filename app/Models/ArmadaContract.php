<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class ArmadaContract extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['contract_number', 'client_id', 'satuan_tarif', 'tarif_per_rit', 'billed_rit', 'status'])
            ->logOnlyDirty()
            ->useLogName('armada_contract');
    }

    protected $fillable = [
        'company_id',
        'contract_number',
        'client_id',
        'tipe_kontrak',
        'include_bbm',
        'include_operator',
        'route_description',
        'satuan_tarif',
        'tarif_per_rit',
        'bbm_liter_per_rit',
        'harga_bbm_per_liter',
        'gaji_supir_per_hari',
        'uang_makan_per_hari',
        'uang_jalan_per_rit',
        'premi_per_rit',
        'billed_rit',
        'status',
        'started_at',
        'ended_at',
        'notes',
        'created_by',
    ];

    // Integer cast untuk field Rupiah — hindari bug rupiah 100× lipat karena
    // decimal:2 string "250000.00" bikin Filament mask $money salah interpret.
    // Field non-Rp (bbm_liter_per_rit) tetap decimal karena bisa fractional (0.5 L).
    protected $casts = [
        'tarif_per_rit'       => 'integer',
        'billed_rit'          => 'integer',
        'started_at'          => 'date',
        'ended_at'            => 'date',
        'include_bbm'         => 'boolean',
        'include_operator'    => 'boolean',
        'bbm_liter_per_rit'   => 'decimal:2',
        'harga_bbm_per_liter' => 'integer',
        'gaji_supir_per_hari' => 'integer',
        'uang_makan_per_hari' => 'integer',
        'uang_jalan_per_rit'  => 'integer',
        'premi_per_rit'       => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ritLogs(): HasMany
    {
        return $this->hasMany(RitLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mobilizations(): HasMany
    {
        return $this->hasMany(ArmadaMobilization::class);
    }

    // ------------------------------------------------------------------
    // Satuan tarif
    // ------------------------------------------------------------------
    //
    // Kolom `tarif_per_rit` berarti "tarif per SATUAN" (nama kolom dipertahankan
    // supaya laporan, approval & backup lama tidak putus). Kuantitas yang
    // ditagih per log harian:
    //   per_rit → rit_count   per_m3 → volume_m3   per_hari → hari_kerja

    public const SATUAN = [
        'per_rit'  => ['label' => 'Per Rit', 'unit' => 'rit', 'column' => 'rit_count'],
        'per_m3'   => ['label' => 'Per M³', 'unit' => 'M³', 'column' => 'volume_m3'],
        'per_ton'  => ['label' => 'Per Ton (tonase)', 'unit' => 'ton', 'column' => 'tonase'],
        'per_hari' => ['label' => 'Per Hari (rental harian)', 'unit' => 'hari', 'column' => 'hari_kerja'],
    ];

    public function satuan(): string
    {
        return array_key_exists((string) $this->satuan_tarif, self::SATUAN) ? $this->satuan_tarif : 'per_rit';
    }

    /** "rit" / "M³" / "hari" */
    public function unitLabel(): string
    {
        return self::SATUAN[$this->satuan()]['unit'];
    }

    /** Kolom rit_logs yang menjadi kuantitas tagihan. */
    public function billableColumn(): string
    {
        return self::SATUAN[$this->satuan()]['column'];
    }

    public function billableQty(RitLog $log): float
    {
        return (float) ($log->{$this->billableColumn()} ?? 0);
    }

    /**
     * Yang belum ditagih: kuantitas log (sesuai satuan) + ongkos gendong.
     * Sumber kebenaran = invoice_id kosong (bukan counter billed_rit), supaya
     * benar untuk semua satuan dan setelah invoice di-void.
     *
     * @return array{qty: float, usage_value: float, mob_count: int, mob_value: float, total: float}
     */
    public function unbilledSummary(): array
    {
        // Atribut unbilled_* diisi oleh scopeWithUnbilled() (daftar kontrak) —
        // tanpa itu dihitung langsung (1 query per pemanggilan).
        $qty = (float) $this->preloaded('unbilled_' . $this->billableColumn(),
            fn () => $this->ritLogs()->whereNull('invoice_id')->sum($this->billableColumn()));
        $mobCount = (int) $this->preloaded('unbilled_mob_count',
            fn () => $this->mobilizations()->whereNull('invoice_id')->count());
        $mobValue = (float) $this->preloaded('unbilled_mob_sum',
            fn () => $this->mobilizations()->whereNull('invoice_id')->sum('amount'));

        // Nilai = Σ (kuantitas × tarif yang terkunci di log masing-masing).
        $usage = round((float) $this->preloaded('unbilled_value_' . $this->billableColumn(),
            fn () => $this->ritLogs()->whereNull('invoice_id')->sum(\DB::raw($this->valueExpression($this->billableColumn())))), 2);

        return [
            'qty'         => round($qty, 2),
            'usage_value' => $usage,
            'mob_count'   => $mobCount,
            'mob_value'   => $mobValue,
            'total'       => round($usage + $mobValue, 2),
        ];
    }

    /**
     * Nilai agregat yang sudah dimuat scopeWithUnbilled() — withSum memberi NULL
     * bila tidak ada baris, jadi yang dicek keberadaan KUNCI, bukan nilainya.
     */
    private function preloaded(string $key, \Closure $query): mixed
    {
        return array_key_exists($key, $this->attributes) ? ($this->attributes[$key] ?? 0) : $query();
    }

    /**
     * SQL nilai satu log: kuantitas × tarif log. Tarif NULL (log sebelum migrasi
     * tarif per log yang tak ter-backfill) memakai tarif kontrak.
     */
    public static function valueExpressionFor(string $column): string
    {
        $col = in_array($column, array_column(self::SATUAN, 'column'), true) ? $column : 'rit_count';

        return "COALESCE(rit_logs.{$col}, 0) * COALESCE(rit_logs.tarif, (SELECT ac.tarif_per_rit FROM armada_contracts ac WHERE ac.id = rit_logs.armada_contract_id))";
    }

    private function valueExpression(string $column): string
    {
        return self::valueExpressionFor($column);
    }

    /** Muat semua angka "belum ditagih" sekaligus untuk banyak kontrak (hindari N+1). */
    public function scopeWithUnbilled(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $unbilled = fn ($q) => $q->whereNull('invoice_id');

        foreach (array_column(self::SATUAN, 'column') as $col) {
            $query->withSum(["ritLogs as unbilled_{$col}" => $unbilled], $col);
            $query->withSum(["ritLogs as unbilled_value_{$col}" => $unbilled], \DB::raw(self::valueExpressionFor($col)));
        }

        return $query
            ->withSum(['ritLogs as total_rit_sum'], 'rit_count')
            ->withCount(['mobilizations as unbilled_mob_count' => $unbilled])
            ->withSum(['mobilizations as unbilled_mob_sum' => $unbilled], 'amount');
    }

    public function hasUnbilled(): bool
    {
        $s = $this->unbilledSummary();

        return $s['qty'] > 0 || $s['mob_count'] > 0;
    }

    /** "120 M³ + 2 mobilisasi alat" — untuk tombol, notifikasi, pesan error. */
    public function unbilledLabel(): string
    {
        $s = $this->unbilledSummary();
        $qty = rtrim(rtrim(number_format($s['qty'], 2, ',', '.'), '0'), ',');

        return implode(' + ', array_filter([
            $s['qty'] > 0 ? "{$qty} {$this->unitLabel()}" : null,
            $s['mob_count'] > 0 ? "{$s['mob_count']} mobilisasi alat" : null,
        ]));
    }

    /** Total rit dari semua RitLog */
    public function getTotalRitAttribute(): int
    {
        return (int) $this->preloaded('total_rit_sum', fn () => $this->ritLogs()->sum('rit_count'));
    }

    /** Rit yang belum ditagih = total - billed */
    public function getUnbilledRitAttribute(): int
    {
        return max(0, $this->total_rit - (int) $this->billed_rit);
    }

    /** Nilai yang siap ditagih (pemakaian sesuai satuan + ongkos gendong). */
    public function getNilaiSiapTagihAttribute(): float
    {
        return $this->unbilledSummary()['total'];
    }

    public function isAktif(): bool   { return $this->status === 'aktif'; }
    public function isSelesai(): bool { return $this->status === 'selesai'; }

    /**
     * Derive include_bbm/operator dari tipe_kontrak — sama pattern dengan
     * RentalContract. Kolom DB flag diabaikan (safety terhadap UI drift).
     */
    public function includesBbm(): bool
    {
        return $this->tipe_kontrak === 'all_in';
    }

    public function includesOperator(): bool
    {
        return in_array($this->tipe_kontrak, ['all_in', 'semi'], true);
    }

    protected static function booted(): void
    {
        // Kolom include_* selalu turunan tipe_kontrak (lihat includesBbm()).
        // Form tidak punya field-nya, jadi tanpa ini nilainya bisa basi
        // (KA26-0002: Alat Saja tapi include_* = 1).
        static::saving(function (ArmadaContract $contract) {
            $contract->include_bbm      = $contract->includesBbm();
            $contract->include_operator = $contract->includesOperator();
        });

        // Block hapus jika sudah ada invoice atau billed_rit > 0
        static::deleting(function (ArmadaContract $contract) {
            if ((int) $contract->billed_rit > 0) {
                throw new \RuntimeException(
                    "Kontrak {$contract->contract_number} sudah ada rit yang ditagih. Void invoice terkait dulu sebelum hapus."
                );
            }
            // Cek apakah ada invoice yang link ke kontrak ini
            $hasInvoice = \App\Models\Invoice::withoutGlobalScopes()
                ->where('source_type', 'armada_contract')
                ->where('source_id', $contract->id)
                ->whereNotIn('status', ['void'])
                ->exists();

            if ($hasInvoice) {
                throw new \RuntimeException(
                    "Kontrak {$contract->contract_number} masih punya invoice aktif. Void invoice terkait dulu."
                );
            }
        });

        // Satuan tarif terkunci begitu ada log harian: log lama diisi untuk
        // satuan lama (mis. tanpa volume M³) — ganti satuan = tagihan salah.
        static::updating(function (ArmadaContract $contract) {
            if ($contract->isDirty('satuan_tarif') && $contract->ritLogs()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'satuan_tarif' => "Satuan tarif kontrak {$contract->contract_number} tidak bisa diubah karena sudah ada log harian. Buat kontrak baru untuk satuan lain.",
                ]);
            }
        });

        // Validasi transisi status → 'selesai' (sama pola dengan RentalContract).
        // Kontrak armada hanya boleh selesai kalau semua rit sudah ditagih.
        static::saving(function (ArmadaContract $contract) {
            if (! $contract->isDirty('status')) {
                return;
            }
            if ($contract->status !== 'selesai') {
                return;
            }

            if ($contract->exists && $contract->hasUnbilled()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => sprintf(
                        'Kontrak %s tidak bisa diselesaikan: masih ada %s yang belum ditagih (nilai Rp %s). Tagih dulu sebelum ubah status ke Selesai.',
                        $contract->contract_number,
                        $contract->unbilledLabel(),
                        number_format($contract->unbilledSummary()['total'], 0, ',', '.'),
                    ),
                ]);
            }
        });
    }
}
