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
        'salvage_value',
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
            'useful_life_months'  => 'integer',
            'depreciation_method' => DepreciationMethod::class,
            'useful_life_hours'   => 'decimal:2',
            'useful_life_rits'    => 'decimal:2',
            'useful_life_days'    => 'decimal:2',
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
    protected static function booted(): void
    {
        static::updating(function (Asset $asset) {
            if (! $asset->isDirty('depreciation_method')) {
                return;
            }

            if ($asset->hasPostedDepreciationJournal()) {
                throw new \RuntimeException(sprintf(
                    "Tidak bisa ubah metode depresiasi aset [%s]: sudah ada jurnal penyusutan (DEP-* / DEPUSE-*). "
                    . "Void semua jurnal penyusutan terkait terlebih dahulu, atau buat aset baru.",
                    $asset->asset_code,
                ));
            }
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
