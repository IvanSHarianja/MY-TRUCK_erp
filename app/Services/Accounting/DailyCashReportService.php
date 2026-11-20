<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\DebtInstallment;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Harian Keuangan: apa saja yang terjadi pada satu tanggal.
 *
 *   - Posisi kas per rekening: saldo awal hari, masuk, keluar, saldo akhir.
 *   - Rincian mutasi kas (setiap baris jurnal kas/bank hari itu) + akun lawannya.
 *   - Invoice terbit, pembayaran diterima, cicilan hutang dibayar hari itu.
 *   - Perlu perhatian: piutang & cicilan yang jatuh tempo hari itu.
 *
 * Aturan saldo sama dengan Buku Besar / Neraca Saldo: hanya jurnal posted,
 * jurnal pembalik tidak dihitung (efek void = 0), memakai tanggal jurnal.
 */
class DailyCashReportService
{
    /** @return array<string, mixed> */
    public function build(int $companyId, Carbon $date): array
    {
        $day   = $date->copy()->startOfDay();
        $from  = $day->toDateString();
        $until = $day->copy()->addDay()->toDateString();   // batas atas eksklusif

        $accounts = Account::cashAccounts($companyId);
        $ids = $accounts->pluck('id')->all();

        $base = fn () => DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik')
            ->whereIn('jl.account_id', $ids ?: [0]);

        $opening = $base()->where('je.entry_date', '<', $from)
            ->groupBy('jl.account_id')->selectRaw('jl.account_id, SUM(jl.debit) - SUM(jl.kredit) AS net')
            ->pluck('net', 'account_id');

        $lines = $base()->where('je.entry_date', '>=', $from)->where('je.entry_date', '<', $until)
            ->orderBy('je.entry_number')->orderBy('jl.id')
            ->get(['jl.account_id', 'jl.debit', 'jl.kredit', 'jl.description as line_description',
                'je.id as entry_id', 'je.entry_number', 'je.document_number', 'je.description as entry_description']);

        // Akun lawan per jurnal (baris non-kas) — "uang ini untuk/dari apa".
        $entryIds = $lines->pluck('entry_id')->unique()->all();
        $counter = $entryIds === [] ? collect() : DB::table('journal_entry_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('jl.journal_entry_id', $entryIds)
            ->whereNotIn('jl.account_id', $ids ?: [0])
            ->get(['jl.journal_entry_id', 'a.name'])
            ->groupBy('journal_entry_id')
            ->map(fn ($g) => $g->pluck('name')->unique()->implode(', '));
        // Jurnal yang hanya berisi akun kas = pindah dana antar rekening.
        $names = $accounts->pluck('name', 'id');

        $byAccount = $lines->groupBy('account_id');
        $rekening = [];
        $mutasi = [];
        foreach ($accounts as $acc) {
            $rows = $byAccount->get($acc->id, collect());
            $open = round((float) ($opening[$acc->id] ?? 0), 2);
            $in   = round((float) $rows->sum('debit'), 2);
            $out  = round((float) $rows->sum('kredit'), 2);
            if ($rows->isEmpty() && abs($open) < 0.005) {
                continue;   // rekening kosong & tidak bergerak
            }
            $rekening[] = [
                'code' => $acc->code, 'name' => $acc->name,
                'opening' => $open, 'in' => $in, 'out' => $out, 'closing' => round($open + $in - $out, 2),
            ];
        }

        foreach ($lines as $l) {
            $lawan = $counter[$l->entry_id] ?? null;
            $mutasi[] = [
                'entry_id'        => (int) $l->entry_id,
                'entry_number'    => $l->entry_number,
                'document_number' => $l->document_number,
                'rekening'        => $names[$l->account_id] ?? '—',
                'description'     => $l->line_description ?: $l->entry_description,
                'lawan'           => $lawan ?: 'Pindah dana antar rekening',
                'in'              => (float) $l->debit,
                'out'             => (float) $l->kredit,
            ];
        }

        $totals = [
            'opening' => round(array_sum(array_column($rekening, 'opening')), 2),
            'in'      => round(array_sum(array_column($rekening, 'in')), 2),
            'out'     => round(array_sum(array_column($rekening, 'out')), 2),
            'closing' => round(array_sum(array_column($rekening, 'closing')), 2),
        ];

        $invoices = Invoice::withoutGlobalScopes()->with('client')
            ->where('company_id', $companyId)
            ->whereIn('status', ['terbit', 'sebagian', 'lunas'])
            ->whereDate('invoice_date', $day)
            ->orderBy('invoice_number')->get();

        $payments = Payment::withoutGlobalScopes()->with(['invoice.client', 'cashAccount'])
            ->where('company_id', $companyId)
            ->whereDate('payment_date', $day)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->orderBy('payment_number')->get();

        $installments = DebtInstallment::withoutGlobalScopes()->with(['debt', 'cashAccount'])
            ->where('company_id', $companyId)
            ->where('status', 'lunas')
            ->whereDate('paid_date', $day)
            ->orderBy('id')->get();

        $dueInvoices = Invoice::withoutGlobalScopes()->with('client')
            ->where('company_id', $companyId)
            ->whereIn('status', ['terbit', 'sebagian'])
            ->whereDate('due_date', $day)
            ->orderBy('invoice_number')->get();

        $dueInstallments = DebtInstallment::withoutGlobalScopes()->with('debt')
            ->where('company_id', $companyId)
            ->where('status', 'belum')
            ->whereDate('due_date', $day)
            ->orderBy('id')->get();

        return [
            'date'             => $day,
            'rekening'         => $rekening,
            'totals'           => $totals,
            'mutasi'           => $mutasi,
            'invoices'         => $invoices,
            'payments'         => $payments,
            'installments'     => $installments,
            'due_invoices'     => $dueInvoices,
            'due_installments' => $dueInstallments,
        ];
    }
}
