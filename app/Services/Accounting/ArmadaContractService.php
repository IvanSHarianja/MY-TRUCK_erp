<?php

namespace App\Services\Accounting;

use App\Models\ArmadaContract;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Invoice;
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

        $lastNumber = ArmadaContract::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('contract_number', 'like', $prefix . '%')
            ->orderByDesc('contract_number')
            ->value('contract_number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Tagih semua rit yang belum ditagih → bikin Invoice + auto-issue.
     *
     * Flow:
     * 1. Hitung unbilled rit dari rit_logs WHERE invoice_id IS NULL
     * 2. amount = total_unbilled × tarif_per_rit
     * 3. Bikin Invoice (BusinessUnit ARMD, akun pendapatan 441200)
     * 4. Issue invoice (jurnal Dr Piutang Cr Pendapatan otomatis)
     * 5. Update semua RitLog yang ditagih → set invoice_id
     * 6. Update billed_rit counter di kontrak
     */
    public function tagih(ArmadaContract $contract, ?CarbonInterface $invoiceDate = null): Invoice
    {
        if (! $contract->isAktif()) {
            throw ValidationException::withMessages([
                'status' => "Hanya kontrak status AKTIF yang bisa ditagih (status sekarang: {$contract->status}).",
            ]);
        }

        $contract->load('client');

        // Ambil rit_logs yang belum ditagih
        $unbilledLogs = $contract->ritLogs()
            ->whereNull('invoice_id')
            ->get();

        $totalUnbilledRit = (int) $unbilledLogs->sum('rit_count');

        if ($totalUnbilledRit <= 0) {
            throw ValidationException::withMessages([
                'rit' => "Tidak ada rit yang belum ditagih di kontrak {$contract->contract_number}.",
            ]);
        }

        $amount = $totalUnbilledRit * (float) $contract->tarif_per_rit;
        $invDate = $invoiceDate ? Carbon::parse($invoiceDate) : Carbon::today();
        $company = Company::findOrFail($contract->company_id);

        // BusinessUnit ARMD
        $armdUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'ARMD')
            ->first();

        return DB::transaction(function () use (
            $contract, $unbilledLogs, $totalUnbilledRit, $amount, $invDate, $armdUnit
        ) {
            // 1. Bikin invoice draft
            $invoice = Invoice::create([
                'company_id'       => $contract->company_id,
                'invoice_number'   => 'DRAFT-' . now()->format('ymdHisu'),
                'invoice_date'     => $invDate,
                'due_date'         => $invDate->copy()->addDays(30),
                'client_id'        => $contract->client_id,
                'business_unit_id' => optional($armdUnit)->id,
                'description'      => sprintf(
                    'Angkutan %d rit @ Rp %s — %s (%s)',
                    $totalUnbilledRit,
                    number_format($contract->tarif_per_rit, 0, ',', '.'),
                    $contract->route_description,
                    $contract->contract_number,
                ),
                'amount'           => $amount,
                'paid_amount'      => 0,
                'status'           => 'draft',
                'source_type'      => 'armada_contract',
                'source_id'        => $contract->id,
                'created_by'       => Auth::id() ?? $contract->created_by,
            ]);

            // 2. Issue invoice (jurnal otomatis Dr Piutang Cr Pendapatan)
            $invoice = $this->invoiceService->issue($invoice);

            // 3. Update rit_logs → set invoice_id
            $unbilledLogs->each(function ($log) use ($invoice) {
                $log->update(['invoice_id' => $invoice->id]);
            });

            // 4. Update kontrak billed_rit
            $contract->update([
                'billed_rit' => (int) $contract->billed_rit + $totalUnbilledRit,
            ]);

            return $invoice;
        });
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

        if ($contract->unbilled_rit > 0) {
            throw ValidationException::withMessages([
                'rit' => "Masih ada {$contract->unbilled_rit} rit belum ditagih. Tagih dulu sebelum tutup kontrak.",
            ]);
        }

        $contract->update([
            'status'   => 'selesai',
            'ended_at' => $endDate ? Carbon::parse($endDate) : Carbon::today(),
        ]);

        return $contract->refresh();
    }
}
