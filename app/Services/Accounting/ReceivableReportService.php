<?php

namespace App\Services\Accounting;

use App\Support\PaymentTiming;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Tagihan & Piutang: umur piutang per klien, invoice belum lunas,
 * riwayat pembayaran, dan ringkasan ketepatan bayar klien.
 *
 * Semua angka dihitung PER TANGGAL ($asOf) dari tabel pembayaran — bukan
 * kolom invoices.paid_amount yang hanya mencerminkan hari ini — supaya
 * laporan akhir bulan lalu tetap bisa dibuat ulang dengan benar.
 *
 * Pembayaran yang jurnalnya di-void dianggap batal (tidak mengurangi piutang).
 * Draft & invoice void tidak dihitung sebagai piutang.
 */
class ReceivableReportService
{
    /**
     * Invoice terbit s.d. $asOf beserta pembayaran sah s.d. $asOf.
     *
     * @return Collection<int, object>  tiap item: invoice + client_code/name, paid, sisa, due, days_late, bucket, settled_date
     */
    public function invoicesAsOf(int $companyId, CarbonInterface $asOf, ?int $clientId = null): Collection
    {
        $until = Carbon::parse($asOf)->addDay()->toDateString();   // eksklusif: aman untuk kolom date ber-jam (SQLite)

        $invoices = DB::table('invoices as i')
            ->join('clients as c', 'c.id', '=', 'i.client_id')
            ->leftJoin('business_units as bu', 'bu.id', '=', 'i.business_unit_id')
            ->where('i.company_id', $companyId)
            ->whereNotIn('i.status', ['draft', 'void'])
            ->where('i.invoice_date', '<', $until)
            ->when($clientId, fn ($q) => $q->where('i.client_id', $clientId))
            ->orderBy('c.name')->orderBy('i.invoice_date')->orderBy('i.id')
            ->select('i.id', 'i.invoice_number', 'i.invoice_date', 'i.due_date', 'i.amount', 'i.description',
                'i.client_id', 'c.code as client_code', 'c.name as client_name', 'bu.code as lini')
            ->get();

        $payments = $this->validPayments($companyId, $until)
            ->whereIn('p.invoice_id', $invoices->pluck('id')->all())
            ->get()
            ->groupBy('invoice_id');

        return $invoices->map(function ($inv) use ($payments, $asOf) {
            $paid    = ($payments[$inv->id] ?? collect());
            $inv->paid  = round((float) $paid->sum('amount'), 2);
            $inv->sisa  = round((float) $inv->amount - $inv->paid, 2);
            $inv->due   = Carbon::parse($inv->due_date ?? $inv->invoice_date)->startOfDay();
            $inv->lunas = $inv->sisa <= 0.005;
            $inv->settled_date = $inv->lunas && $paid->isNotEmpty() ? Carbon::parse($paid->max('payment_date')) : null;
            $inv->days_late = PaymentTiming::daysLate($inv->due, $inv->lunas ? ($inv->settled_date ?? $inv->due) : $asOf);
            $inv->bucket    = $inv->lunas ? null : PaymentTiming::bucket($inv->days_late);

            return $inv;
        });
    }

    /** Invoice yang masih bersisa per $asOf, telat paling lama di atas. */
    public function outstandingInvoices(int $companyId, CarbonInterface $asOf, ?int $clientId = null): Collection
    {
        return $this->invoicesAsOf($companyId, $asOf, $clientId)
            ->reject(fn ($i) => $i->lunas)
            ->sortByDesc('days_late')
            ->values();
    }

    /**
     * Umur piutang per klien per $asOf: sisa per kelompok telat + ketepatan
     * bayar historis (rata-rata hari telat invoice yang sudah lunas).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function agingByClient(int $companyId, CarbonInterface $asOf, ?int $clientId = null): Collection
    {
        return $this->invoicesAsOf($companyId, $asOf, $clientId)
            ->groupBy('client_id')
            ->map(function (Collection $invs) {
                $first = $invs->first();
                $open  = $invs->reject(fn ($i) => $i->lunas);
                $paid  = $invs->filter(fn ($i) => $i->lunas);

                $row = [
                    'client_id'      => $first->client_id,
                    'client_code'    => $first->client_code,
                    'client_name'    => $first->client_name,
                    'total_sisa'     => round((float) $open->sum('sisa'), 2),
                    'jumlah_invoice' => $open->count(),
                    'telat_maks'     => (int) $open->max('days_late'),
                    'lunas_jumlah'   => $paid->count(),
                    'lunas_telat'    => $paid->where('days_late', '>', 0)->count(),
                    'rata_telat'     => $paid->isNotEmpty() ? round((float) $paid->avg('days_late'), 1) : null,
                ];
                foreach (array_keys(PaymentTiming::BUCKETS) as $b) {
                    $row["bucket_{$b}"] = round((float) $open->where('bucket', $b)->sum('sisa'), 2);
                }

                return $row;
            })
            ->sortByDesc('total_sisa')
            ->values();
    }

    /**
     * Riwayat pembayaran dalam rentang, dengan ketepatan per pembayaran
     * (tanggal bayar − jatuh tempo invoice). Termasuk yang batal (ditandai).
     */
    public function payments(int $companyId, CarbonInterface $dari, CarbonInterface $sampai, ?int $clientId = null): Collection
    {
        return DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('clients as c', 'c.id', '=', 'i.client_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'p.cash_account_id')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'p.journal_entry_id')
            ->where('p.company_id', $companyId)
            ->where('p.payment_date', '>=', Carbon::parse($dari)->toDateString())
            ->where('p.payment_date', '<', Carbon::parse($sampai)->addDay()->toDateString())
            ->when($clientId, fn ($q) => $q->where('i.client_id', $clientId))
            ->orderByDesc('p.payment_date')->orderByDesc('p.id')
            ->select('p.id', 'p.payment_number', 'p.payment_date', 'p.amount', 'p.reference_number',
                'i.invoice_number', 'i.invoice_date', 'i.due_date', 'c.code as client_code', 'c.name as client_name',
                'a.name as cash_account', 'je.status as journal_status')
            ->get()
            ->map(function ($p) {
                $due = Carbon::parse($p->due_date ?? $p->invoice_date);
                $p->days_late = PaymentTiming::daysLate($due, Carbon::parse($p->payment_date));
                $p->batal     = $p->journal_status === 'void';

                return $p;
            });
    }

    /**
     * Ringkasan satu klien (halaman detail klien).
     *
     * @return array{ditagih: float, dibayar: float, sisa: float, telat_terbuka: int, rata_telat: ?float, lunas_telat: int, lunas_jumlah: int}
     */
    public function clientSummary(int $companyId, int $clientId, ?CarbonInterface $asOf = null): array
    {
        $invs = $this->invoicesAsOf($companyId, $asOf ?? Carbon::today(), $clientId);
        $paid = $invs->filter(fn ($i) => $i->lunas);
        $open = $invs->reject(fn ($i) => $i->lunas);

        return [
            'ditagih'       => round((float) $invs->sum('amount'), 2),
            'dibayar'       => round((float) $invs->sum('paid'), 2),
            'sisa'          => round((float) $open->sum('sisa'), 2),
            'telat_terbuka' => $open->where('days_late', '>', 0)->count(),
            'rata_telat'    => $paid->isNotEmpty() ? round((float) $paid->avg('days_late'), 1) : null,
            'lunas_telat'   => $paid->where('days_late', '>', 0)->count(),
            'lunas_jumlah'  => $paid->count(),
        ];
    }

    /** Pembayaran sah (jurnal tidak void) s.d. tanggal eksklusif $untilStr. */
    private function validPayments(int $companyId, string $untilStr)
    {
        return DB::table('payments as p')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'p.journal_entry_id')
            ->where('p.company_id', $companyId)
            ->where('p.payment_date', '<', $untilStr)
            ->where(fn ($q) => $q->whereNull('je.status')->orWhere('je.status', '!=', 'void'))
            ->select('p.invoice_id', 'p.amount', 'p.payment_date');
    }
}
