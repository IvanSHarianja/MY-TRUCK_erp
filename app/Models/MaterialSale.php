<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBuktiTf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class MaterialSale extends Model
{
    use BelongsToCompany;
    use HasBuktiTf;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sale_number', 'sale_date', 'client_id', 'material_id', 'volume', 'total', 'metode'])
            ->logOnlyDirty()
            ->useLogName('material_sale');
    }

    protected $fillable = [
        'company_id',
        'sale_number',
        'sale_date',
        'client_id',
        'material_id',
        'volume',
        'satuan_jual',
        'jumlah_rit',
        'asset_id',
        'driver_id',
        'ongkos_supir_per_rit',
        'uang_makan_supir',
        'biaya_bbm',
        'bm_per_rit',
        'biaya_portal',
        'biaya_lain',
        'biaya_lain_ket',
        'biaya_kirim_total',
        'biaya_cash_account_id',
        'biaya_journal_id',
        'hpp_total',
        'harga_satuan',
        'total',
        'metode',
        'cash_account_id',
        'invoice_id',
        'journal_entry_id',
        'voided_at',
        'voided_by',
        'void_reason',
        'notes',
        'created_by',
        'bukti_tf_path',
    ];

    // Integer cast untuk uang. volume tetap decimal karena m³ bisa fractional.
    protected $casts = [
        'sale_date'            => 'date',
        'voided_at'            => 'datetime',
        'volume'               => 'decimal:2',
        'harga_satuan'         => 'integer',
        'total'                => 'integer',
        'jumlah_rit'           => 'decimal:2',
        'ongkos_supir_per_rit' => 'integer',
        'uang_makan_supir'     => 'integer',
        'biaya_bbm'            => 'integer',
        'bm_per_rit'           => 'integer',
        'biaya_portal'         => 'integer',
        'biaya_lain'           => 'integer',
        'biaya_kirim_total'    => 'integer',
        'hpp_total'            => 'integer',
    ];

    /**
     * Komponen biaya pengiriman → [label, Peran akun beban, kode standar].
     * Ongkos supir & BM dihitung per rit (× jumlah_rit); sisanya nominal.
     */
    public const DELIVERY_COSTS = [
        'ongkos_supir'     => ['Ongkos supir', \App\Enums\AccountRole::CogsPremiUangJalan, '551200'],
        'uang_makan_supir' => ['Uang makan supir', \App\Enums\AccountRole::CogsPremiUangJalan, '551200'],
        'biaya_bbm'        => ['BBM', \App\Enums\AccountRole::CogsBbm, '551100'],
        'bm'               => ['Bongkar muat (BM)', \App\Enums\AccountRole::CogsSubkontraktor, '551600'],
        'biaya_portal'     => ['Portal', \App\Enums\AccountRole::CogsPremiUangJalan, '551200'],
        'biaya_lain'       => ['Lain-lain', \App\Enums\AccountRole::OpexLain, '553000'],
    ];

    /** @return array<string, float> komponen → nominal (per-rit sudah dikali jumlah rit) */
    public function deliveryCostBreakdown(): array
    {
        $rit = (float) $this->jumlah_rit;

        return [
            'ongkos_supir'     => round($rit * (float) $this->ongkos_supir_per_rit, 2),
            'uang_makan_supir' => (float) $this->uang_makan_supir,
            'biaya_bbm'        => (float) $this->biaya_bbm,
            'bm'               => round($rit * (float) $this->bm_per_rit, 2),
            'biaya_portal'     => (float) $this->biaya_portal,
            'biaya_lain'       => (float) $this->biaya_lain,
        ];
    }

    /** "10 rit (60 m3)" untuk jual per rit, "60 m3" untuk jual per satuan. */
    public function quantityLabel(): string
    {
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        $vol = $num($this->volume) . ' ' . ($this->material?->satuan ?? '');

        return $this->satuan_jual === 'rit' ? $num($this->jumlah_rit) . " rit ({$vol})" : $vol;
    }

    /** Margin = pendapatan − HPP − biaya kirim. */
    public function margin(): float
    {
        return round((float) $this->total - (float) $this->hpp_total - (float) $this->biaya_kirim_total, 2);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoided(): bool  { return $this->voided_at !== null; }
    public function isTunai(): bool   { return $this->metode === 'tunai'; }
    public function isInvoice(): bool { return $this->metode === 'invoice'; }
}
