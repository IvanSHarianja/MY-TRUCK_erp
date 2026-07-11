<?php

namespace App\Models;

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
        'salvage_value',
        'account_id',
        'default_business_unit_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date'      => 'date',
            'purchase_price'     => 'decimal:2',
            'salvage_value'      => 'decimal:2',
            'useful_life_months' => 'integer',
        ];
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
        if (! $this->useful_life_months || $this->useful_life_months <= 0) {
            return 0;
        }

        return round(
            ((float) $this->purchase_price - (float) $this->salvage_value) / $this->useful_life_months,
            2,
        );
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
