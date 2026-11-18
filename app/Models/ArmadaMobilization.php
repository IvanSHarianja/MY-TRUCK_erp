<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Mobilisasi Alat Berat: biaya mengangkut alat berat (excavator, dozer, …) ke/dari
 * lokasi dengan truk self loader. Ditagih bersama ritase kontrak lewat
 * tombol Tagih (ArmadaContractService::tagih) — invoice_id terisi setelahnya.
 */
class ArmadaMobilization extends Model
{
    use BelongsToCompany;
    use LogsActivity;

    public const JENIS = [
        'mobilisasi'   => 'Mobilisasi (antar ke lokasi)',
        'demobilisasi' => 'Demobilisasi (tarik dari lokasi)',
    ];

    protected $fillable = [
        'company_id',
        'armada_contract_id',
        'tanggal',
        'jenis',
        'asset_id',
        'alat_diangkut',
        'amount',
        'invoice_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'amount'  => 'integer',     // Rupiah tanpa sen — lihat catatan cast di ArmadaContract
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tanggal', 'jenis', 'alat_diangkut', 'amount', 'invoice_id'])
            ->logOnlyDirty()
            ->useLogName('armada_mobilization');
    }

    public function armadaContract(): BelongsTo
    {
        return $this->belongsTo(ArmadaContract::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isBilled(): bool
    {
        return $this->invoice_id !== null;
    }

    public function jenisLabel(): string
    {
        return $this->jenis === 'demobilisasi' ? 'Demobilisasi' : 'Mobilisasi';
    }
}
