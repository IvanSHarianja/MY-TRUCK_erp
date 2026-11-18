<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtInstallment extends Model
{
    use BelongsToCompany;

    /** Hari sebelum jatuh tempo yang dianggap "segera". */
    public const SOON_DAYS = 7;

    protected $fillable = [
        'company_id', 'debt_id', 'ke', 'due_date', 'pokok', 'bunga', 'status',
        'paid_date', 'paid_pokok', 'paid_bunga', 'denda', 'cash_account_id', 'journal_entry_id', 'paid_by',
    ];

    protected $casts = [
        'due_date'   => 'date',
        'paid_date'  => 'date',
        'pokok'      => 'integer',
        'bunga'      => 'integer',
        'paid_pokok' => 'integer',
        'paid_bunga' => 'integer',
        'denda'      => 'integer',
    ];

    public function debt(): BelongsTo { return $this->belongsTo(Debt::class); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }

    public function isPaid(): bool { return $this->status === 'lunas'; }

    public function total(): int { return (int) $this->pokok + (int) $this->bunga; }

    public function paidTotal(): int { return (int) $this->paid_pokok + (int) $this->paid_bunga + (int) $this->denda; }

    /** Hari menuju jatuh tempo (negatif = sudah lewat). */
    public function daysToDue(): int
    {
        return (int) today()->diffInDays($this->due_date, false);
    }

    /** overdue | soon | later | paid */
    public function urgency(): string
    {
        if ($this->isPaid()) {
            return 'paid';
        }
        $d = $this->daysToDue();

        return $d < 0 ? 'overdue' : ($d <= self::SOON_DAYS ? 'soon' : 'later');
    }

    public function dueLabel(): string
    {
        if ($this->isPaid()) {
            return 'Lunas ' . $this->paid_date?->format('d M Y');
        }
        $d = $this->daysToDue();

        return match (true) {
            $d < 0   => 'Telat ' . abs($d) . ' hari',
            $d === 0 => 'Jatuh tempo hari ini',
            default  => "{$d} hari lagi",
        };
    }
}
