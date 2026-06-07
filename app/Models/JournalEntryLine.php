<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
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
}
