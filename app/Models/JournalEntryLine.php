<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'asset_id',
        'description',
        'debit',
        'kredit',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'debit'      => 'decimal:2',
            'kredit'     => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Aset yang ter-tag pada line ini — untuk cost tracking per unit.
     * Nullable: hanya line beban/pendapatan operasional yang related aset
     * spesifik yang di-tag. Kas, piutang, utang, admin, dll → NULL.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
