<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'asset_code',
        'name',
        'type',
        'plate_number',
        'purchase_date',
        'purchase_price',
        'useful_life_months',
        'depreciation_method',
        'useful_life_hours',
        'useful_life_rits',
        'useful_life_days',
        'monthly_target_hours',
        'salvage_value',
        'akumulasi_awal',
        'akumulasi_awal_per',
        'account_id',
        'default_business_unit_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date'       => 'date',
            // Integer cast (bukan decimal:2) — Rupiah UMKM tidak pakai sen.
            // Wajib untuk field ber-mask ->rupiah() di Filament: string "20000000.00"
            // dari decimal:2 bikin $money mask salah interpret titik sebagai thousand
            // separator → tampil 100.000.000× lipat. Kolom DB decimal(20,2) tetap;
            // hanya bagaimana Eloquent hidrate saja yang berubah.
            'purchase_price'      => 'integer',
            'salvage_value'       => 'integer',
            'akumulasi_awal'      => 'integer',
            'akumulasi_awal_per'  => 'date',
            'useful_life_months'  => 'integer',
            'depreciation_method' => DepreciationMethod::class,
            'useful_life_hours'   => 'decimal:2',
            'useful_life_rits'    => 'decimal:2',
            'useful_life_days'    => 'decimal:2',
            'monthly_target_hours'=> 'decimal:2',
        ];
    }

    /**
     * BIZ-02: kunci method depresiasi setelah aset punya jurnal DEP-* / DEPUSE-*.
     *
     * Rasional: mengubah method di tengah masa hidup aset menyebabkan
     * double-counting atau gap penyusutan di periode transisi (Q6 di sprint plan).
     * Kalau accounting benar-benar butuh ubah, harus dilakukan manual accountant:
     * void semua DEP-* / DEPUSE-* di periode setelah change-point → baru ubah.
     */
    /** Field yang menentukan besar penyusutan — terkunci setelah ada jurnal DEP-/DEPUSE-. */
    public const LOCKED_AFTER_DEPRECIATION = [
        'depreciation_method', 'type', 'purchase_price', 'salvage_value', 'purchase_date',
        'useful_life_months', 'akumulasi_awal',
        // useful_life_hours/rits/days SENGAJA tidak dikunci: revisi estimasi umur
        // pakai berlaku prospektif (PSAK 16 par. 51) — form memberi peringatan.
    ];

    private const LOCKED_LABELS = [
        'depreciation_method' => 'metode penyusutan',
        'type'                => 'jenis aset',
        'purchase_price'      => 'harga beli',
        'salvage_value'       => 'nilai sisa',
        'purchase_date'       => 'tanggal beli',
        'useful_life_months'  => 'umur ekonomis',
        'akumulasi_awal'      => 'akumulasi penyusutan awal',
    ];

    protected static function booted(): void
    {
        // Tanda "Perlu dilengkapi: harga beli, tanggal beli." dari import Excel
        // hilang sendiri begitu isian itu dilengkapi lewat form Edit.
        // Akumulasi awal = penyusutan s/d akhir bulan lalu (lihat estimatedAkumulasiAwal).
        // Catat tanggalnya supaya bulan-bulan itu tidak disusutkan lagi oleh MY-TRUCK.
        static::saving(function (Asset $asset) {
            if ((float) $asset->akumulasi_awal <= 0) {
                $asset->akumulasi_awal_per = null;
            } elseif ($asset->akumulasi_awal_per === null || $asset->isDirty('akumulasi_awal')) {
                $asset->akumulasi_awal_per = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
            }
        });

        static::updating(function (Asset $asset) {
            $asset->notes =\App\Services\Import\AssetImportService::resolveIncompleteNote(
                $asset->notes,
                priceFilled: (float) $asset->purchase_price > 0,
                dateChanged: $asset->isDirty('purchase_date'),
            );
        });

        static::updating(function (Asset $asset) {
            $dirty = array_keys(array_intersect_key($asset->getDirty(), array_flip(self::LOCKED_AFTER_DEPRECIATION)));
            if ($dirty === [] || ! $asset->hasPostedDepreciationJournal()) {
                return;
            }

            // Dulu hanya metode yang dikunci. Mengganti TIPE memindah akun
            // akumulasi (batas maksimal membaca 0 → akumulasi bisa melebihi
            // harga beli); mengganti harga/nilai sisa/umur mengubah tarif bulanan
            // tanpa memperhitungkan yang sudah disusutkan.
            throw \Illuminate\Validation\ValidationException::withMessages([
                $dirty[0] => sprintf(
                    'Aset [%s] sudah punya jurnal penyusutan — %s tidak bisa diubah. '
                    . 'Void jurnal penyusutan terkait dulu, atau catat sebagai aset baru.',
                    $asset->asset_code,
                    implode(', ', array_map(fn ($f) => self::LOCKED_LABELS[$f] ?? $f, $dirty)),
                ),
            ]);
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function defaultBusinessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'default_business_unit_id');
    }

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(AssetMaintenanceLog::class)->orderBy('maintenance_date', 'desc');
    }

    /**
     * Total biaya maintenance sepanjang hidup aset.
     * Dipakai widget "Top 5 aset boros maintenance" nanti.
     */
    public function getTotalMaintenanceCostAttribute(): float
    {
        return (float) $this->maintenanceLogs()->sum('cost');
    }

    /**
     * Riwayat maintenance terakhir — dipakai UI table (badge tanggal).
     */
    public function getLastMaintenanceAttribute(): ?AssetMaintenanceLog
    {
        return $this->maintenanceLogs()->first();
    }

    /**
     * Kode BusinessUnit yang default untuk alokasi biaya (penyusutan, BBM, dst).
     *
     * Aturan resolusi:
     *   1. Bila user sudah pilih default_business_unit_id manual → pakai itu.
     *   2. Kalau belum → fallback berbasis tipe asset:
     *      - dump_truck                                  → ARMD (angkutan)
     *      - excavator, bulldozer, wheel_loader          → RENT (sewa alat)
     *      - kendaraan_operasional, peralatan_kantor,
     *        lainnya                                     → UMUM (admin)
     *
     * Return string kode BU (RENT/ARMD/MATL/BONG/UMUM), bukan model — caller
     * bisa Business Unit::where('code', ...) sendiri untuk fleksibilitas
     * (misal butuh withoutGlobalScopes dari observer/job).
     */
    public function defaultBusinessUnitCode(): string
    {
        if ($this->defaultBusinessUnit) {
            return $this->defaultBusinessUnit->code;
        }

        return match ($this->type) {
            'dump_truck'                                          => 'ARMD',
            'excavator', 'bulldozer', 'wheel_loader'              => 'RENT',
            default                                                => 'UMUM',
        };
    }

    /**
     * Perkiraan akumulasi penyusutan garis lurus dari tanggal beli sampai akhir
     * bulan SEBELUM $asOf (bulan berjalan disusutkan oleh MY-TRUCK sendiri).
     * Dipakai saran di form & import aset lama.
     */
    public function estimatedAkumulasiAwal(?\Carbon\CarbonInterface $asOf = null): int
    {
        $asOf ??= now();
        $months = (int) $this->useful_life_months;
        $base   = (float) $this->purchase_price - (float) $this->salvage_value;
        if (! $this->purchase_date || $months <= 0 || $base <= 0) {
            return 0;
        }

        // Sama dengan DepreciationService: bulan pembelian tidak disusutkan, mulai
        // bulan berikutnya (dulu dihitung dari bulan beli → kelebihan 1 bulan).
        $start   = \Illuminate\Support\Carbon::parse($this->purchase_date)->startOfMonth()->addMonthNoOverflow();
        $end     = \Illuminate\Support\Carbon::parse($asOf)->startOfMonth();
        $elapsed = max(0, ($end->year - $start->year) * 12 + ($end->month - $start->month));

        return (int) round(min($base, $base / $months * $elapsed));
    }

    public function getMonthlyDepreciationAttribute(): float
    {
        // BIZ-02: usage-based method tidak punya "monthly" — depresiasi
        // dihitung per usage log (via observer). Return 0 supaya laporan
        // straight-line tidak salah mengklaim ada monthly untuk aset ini.
        if ($this->depreciation_method?->isUsageBased()) {
            return 0;
        }

        if (! $this->useful_life_months || $this->useful_life_months <= 0) {
            return 0;
        }

        return round(
            ((float) $this->purchase_price - (float) $this->salvage_value) / $this->useful_life_months,
            2,
        );
    }

    /**
     * BIZ-02: biaya penyusutan per unit usage (jam/rit/hari).
     * Return 0 kalau method straight_line atau umur ekonomis belum diisi.
     *
     * Formula: (purchase_price - salvage_value) / useful_life_<unit>
     *
     * Precision: round ke 4 desimal. Rupiah UMKM biasanya bulat, tapi per-unit
     * bisa sub-rupiah (mis. 5jt / 3000 jam = 1666.67). Precision terjaga hingga
     * multiplication di journal amount (yang dibulatkan lagi ke 2 desimal).
     */
    public function depreciationPerUnit(): float
    {
        $method = $this->depreciation_method;

        if (! $method instanceof DepreciationMethod || ! $method->isUsageBased()) {
            return 0.0;
        }

        $useful = match ($method) {
            DepreciationMethod::PerHour => (float) $this->useful_life_hours,
            DepreciationMethod::PerRit  => (float) $this->useful_life_rits,
            DepreciationMethod::PerDay  => (float) $this->useful_life_days,
            default                     => 0.0,
        };

        if ($useful <= 0) {
            return 0.0;
        }

        $depreciableBase = (float) $this->purchase_price - (float) $this->salvage_value;
        if ($depreciableBase <= 0) {
            return 0.0;
        }

        return round($depreciableBase / $useful, 4);
    }

    /**
     * BIZ-02 support: apakah aset ini punya jurnal penyusutan (posted/draft)?
     * Dipakai untuk mengunci perubahan depreciation_method + di UI form
     * (disable field method).
     *
     * Mencakup DEP-* (straight-line monthly) dan DEPUSE-* (usage-based, BIZ-03).
     */
    public function hasPostedDepreciationJournal(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where(function ($q) {
                $q->where('document_number', 'like', sprintf('DEP-%d-%%', $this->id))
                  ->orWhere('document_number', 'like', sprintf('DEPUSE-%d-%%', $this->id));
            })
            ->whereIn('status', ['draft', 'posted'])
            ->exists();
    }

    /**
     * Kode akun Akumulasi Penyusutan yang default untuk tipe asset ini.
     *
     * Mapping sesuai seed COA (CompanyTemplateService::accounts()):
     *   - 112105 Akumulasi Penyusutan Armada           → alat berat operasional
     *   - 112115 Akumulasi Penyusutan Peralatan        → peralatan kantor & fallback
     *   - 112125 Akumulasi Penyusutan Kendaraan Op.    → kendaraan operasional
     *
     * Dipakai DepreciationService (nanti) untuk pilih akun Cr saat jurnal
     * penyusutan bulanan. Explicit match agar tipe baru tidak silent-fallback.
     */
    public function defaultAkumulasiCode(): string
    {
        return match ($this->type) {
            'dump_truck', 'excavator', 'bulldozer', 'wheel_loader' => '112105',
            'kendaraan_operasional'                                => '112125',
            'peralatan_kantor'                                     => '112115',
            default                                                 => '112115',
        };
    }

    /**
     * Kode akun Beban Penyusutan (sisi Debit di jurnal penyusutan).
     *
     * Saat ini semua tipe asset memakai 552100 (Beban Penyusutan). Method
     * disediakan sebagai extension point — bila di masa depan granularity
     * beban perlu dipisah (mis. penyusutan armada vs kantor tercatat di
     * akun berbeda), cukup ubah mapping di sini tanpa menyentuh service.
     */
    public function defaultExpenseAccountCode(): string
    {
        return '552100';
    }
}
