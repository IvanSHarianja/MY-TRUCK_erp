<?php

namespace App\Services\Accounting;

use App\Models\ArmadaContract;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\DocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArmadaContractService
{
    public function __construct(private InvoiceService $invoiceService) {}

    /**
     * Auto-generate nomor kontrak per company per tahun.
     * Format: KA{YY}-{NNNN}, contoh: KA26-0001
     */
    public function generateContractNumber(Company $company, ?CarbonInterface $date = null): string
    {
        $date ??= Carbon::today();
        $prefix = sprintf('KA%02d-', $date->format('y'));

        return DocumentSequence::next(
            ArmadaContract::withoutGlobalScopes()->where('company_id', $company->id),
            'contract_number',
            $prefix,
            4,
        );
    }

    /**
     * Tagih semua yang belum ditagih → satu Invoice + auto-issue.
     *
     * Flow:
     * 1. Log harian WHERE invoice_id IS NULL → kuantitas sesuai satuan tarif
     *    (rit / M³ / hari) × tarif
     * 2. + Ongkos Gendong (mobilisasi) WHERE invoice_id IS NULL
     * 3. Bikin Invoice (BusinessUnit ARMD, akun pendapatan ritase)
     * 4. Link log & ongkos gendong → invoice_id, lalu issue (jurnal piutang)
     * 5. Counter billed_rit tetap menghitung RIT (untuk laporan & backup SOP)
     */
    public function tagih(ArmadaContract $contract, ?CarbonInterface $invoiceDate = null): Invoice
    {
        if (! $contract->isAktif()) {
            throw ValidationException::withMessages([
                'status' => "Hanya kontrak status AKTIF yang bisa ditagih (status sekarang: {$contract->status}).",
            ]);
        }

        $contract->load('client');

        $invDate = $invoiceDate ? Carbon::parse($invoiceDate) : Carbon::today();
        $company = Company::findOrFail($contract->company_id);

        // BusinessUnit ARMD
        $armdUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'ARMD')
            ->first();

        return DB::transaction(function () use (
            $contract, $invDate, $armdUnit
        ) {
            // BUG-06: Lock contract + query unbilled_rit_logs di DALAM transaction.
            // Sama pattern dengan BUG-05 (Rental) — cegah 2 request tagih() bareng
            // yang bikin duplicate invoice + double increment billed_rit.
            $lockedContract = \App\Models\ArmadaContract::withoutGlobalScopes()
                ->where('id', $contract->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedContract || ! $lockedContract->isAktif()) {
                throw ValidationException::withMessages([
                    'status' => "Kontrak {$contract->contract_number} tidak bisa ditagih setelah cek concurrent.",
                ]);
            }

            $unbilledLogs = \App\Models\RitLog::withoutGlobalScopes()
                ->where('armada_contract_id', $contract->id)
                ->whereNull('invoice_id')
                ->lockForUpdate()
                ->get();

            $unbilledMobs = \App\Models\ArmadaMobilization::withoutGlobalScopes()
                ->where('armada_contract_id', $contract->id)
                ->whereNull('invoice_id')
                ->lockForUpdate()
                ->orderBy('tanggal')
                ->get();

            $contract = $lockedContract; // Ganti reference
            $qty      = round((float) $unbilledLogs->sum(fn ($l) => $contract->billableQty($l)), 2);
            // Setiap log ditagih dengan tarif yang terkunci saat log diinput.
            $usage    = round((float) $unbilledLogs->sum(fn ($l) => $contract->billableQty($l) * $l->effectiveTarif($contract)), 2);
            $mobTotal = (float) $unbilledMobs->sum('amount');
            $totalUnbilledRit = (int) $unbilledLogs->sum('rit_count');

            if ($qty <= 0 && $unbilledMobs->isEmpty()) {
                throw ValidationException::withMessages([
                    'rit' => "Tidak ada {$contract->unitLabel()} maupun mobilisasi alat yang belum ditagih di kontrak {$contract->contract_number}.",
                ]);
            }

            // 1. Bikin invoice draft
            $invoice = Invoice::create([
                'company_id'       => $contract->company_id,
                'invoice_number'   => 'DRAFT-' . now()->format('ymdHisu'),
                'invoice_date'     => $invDate,
                'due_date'         => $invDate->copy()->addDays(30),
                'client_id'        => $contract->client_id,
                'business_unit_id' => optional($armdUnit)->id,
                'description'      => $this->invoiceDescription($contract, $unbilledLogs, $unbilledMobs),
                'amount'           => round($usage + $mobTotal, 2),
                'paid_amount'      => 0,
                'status'           => 'draft',
                'source_type'      => 'armada_contract',
                'source_id'        => $contract->id,
                'created_by'       => Auth::id() ?? $contract->created_by,
            ]);

            // 2. Link rit_logs → invoice_id DULU (sebelum issue).
            //    Alasan: InvoiceService::issue() saat post revenue akan panggil
            //    resolveRevenueAssetId() yang cari distinct asset_id dari
            //    rit_logs yang linked ke invoice ini. Kalau link belum ada,
            //    tag asset akan NULL (tidak masuk P&L per unit).
            $unbilledLogs->each(function ($log) use ($invoice) {
                $log->update(['invoice_id' => $invoice->id]);
            });
            $unbilledMobs->each(fn ($m) => $m->update(['invoice_id' => $invoice->id]));

            // 3. Issue invoice (jurnal Dr Piutang Cr Pendapatan; revenue line
            //    di-tag asset_id kalau semua rit pakai 1 aset yang sama).
            $invoice = $this->invoiceService->issue($invoice);

            // 4. BUG-06: Atomic increment (bukan read-modify-write)
            if ($totalUnbilledRit > 0) {
                \App\Models\ArmadaContract::withoutGlobalScopes()
                    ->where('id', $contract->id)
                    ->increment('billed_rit', $totalUnbilledRit);
            }

            return $invoice;
        });
    }

    /**
     * Invoice MY-TRUCK satu baris — rincian pemakaian & ongkos gendong ditulis
     * di keterangan. Contoh:
     *   "Angkutan 120 M³ @ Rp 45.000 — Kuari → Proyek; Ongkos gendong: Mobilisasi
     *    Excavator PC 75 (05/03) Rp 2.500.000 (KA26-0001)"
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\ArmadaMobilization>  $mobs
     */
    private function invoiceDescription(ArmadaContract $contract, $logs, $mobs): string
    {
        $rp  = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');

        // Dikelompokkan per tarif: "6 rit @ Rp 250.000 + 4 rit @ Rp 300.000"
        // bila tarif berubah di tengah periode tagih.
        $perTarif = collect($logs)
            ->groupBy(fn ($l) => (string) $l->effectiveTarif($contract))
            ->map(fn ($g, $tarif) => ['qty' => (float) $g->sum(fn ($l) => $contract->billableQty($l)), 'tarif' => (float) $tarif])
            ->filter(fn ($x) => $x['qty'] > 0)
            ->sortBy('tarif');

        $parts = [];
        if ($perTarif->isNotEmpty()) {
            $kata = $contract->satuan() === 'per_hari' ? 'Sewa' : 'Angkutan';
            $rincian = $perTarif->map(fn ($x) => "{$num($x['qty'])} {$contract->unitLabel()} @ {$rp($x['tarif'])}")->implode(' + ');
            $parts[] = "{$kata} {$rincian} — {$contract->route_description}";
        }
        if ($mobs->isNotEmpty()) {
            $parts[] = 'Mobilisasi alat: ' . $mobs
                ->map(fn ($m) => "{$m->jenisLabel()} {$m->alat_diangkut} ({$m->tanggal->format('d/m')}) {$rp($m->amount)}")
                ->implode(', ');
        }

        return implode('; ', $parts) . " ({$contract->contract_number})";
    }

    /**
     * Tutup kontrak (status: aktif → selesai).
     * Hanya bisa kalau semua rit sudah ditagih (unbilled = 0).
     */
    public function selesai(ArmadaContract $contract, ?CarbonInterface $endDate = null): ArmadaContract
    {
        if (! $contract->isAktif()) {
            throw ValidationException::withMessages([
                'status' => 'Kontrak sudah tidak aktif.',
            ]);
        }

        if ($contract->hasUnbilled()) {
            throw ValidationException::withMessages([
                'rit' => "Masih ada {$contract->unbilledLabel()} belum ditagih. Tagih dulu sebelum tutup kontrak.",
            ]);
        }

        $contract->update([
            'status'   => 'selesai',
            'ended_at' => $endDate ? Carbon::parse($endDate) : Carbon::today(),
        ]);

        return $contract->refresh();
    }
}
