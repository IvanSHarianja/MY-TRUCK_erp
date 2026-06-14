<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialSale extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'sale_number',
        'sale_date',
        'client_id',
        'material_id',
        'volume',
        'harga_satuan',
        'total',
        'metode',
        'cash_account_id',
        'invoice_id',
        'journal_entry_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'sale_date'    => 'date',
        'volume'       => 'decimal:2',
        'harga_satuan' => 'decimal:2',
        'total'        => 'decimal:2',
    ];

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

    public function isTunai(): bool   { return $this->metode === 'tunai'; }
    public function isInvoice(): bool { return $this->metode === 'invoice'; }
}
