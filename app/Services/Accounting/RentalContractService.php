<?php

namespace App\Services\Accounting;

use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RentalContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalContractService
{
    public function __construct(private InvoiceService $invoiceService) {}

    /**
     * Auto-generate nomor kontrak per company per tahun.
     * Format: RN{YY}-{NNNN}, contoh: RN26-0001
     */
    public function generateContractNumber(Company $company, ?CarbonInterface $date = null): string
    {
        $date ??= Carbon::today();
        $prefix = sprintf('RN%02d-', $date->format('y'));

        $lastNumber = RentalContract::withoutGlobalScopes()
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
     * Tagih semua jam yang belum ditagih → bikin Invoice + auto-issue.
     */
    public function tagih(RentalContract $contract, ?CarbonInterface $invoiceDate = null): Invoice
    {
        if (! $contract->isAktif()) {
            throw ValidationException::withMessages([
                'status' => "Hanya kontrak status AKTIF yang bisa ditagih (status sekarang: {$contract->status}).",
            ]);
        }

        $contract->load(['client', 'asset']);

        $unbilledLogs = $contract->rentalLogs()
            ->whereNull('invoice_id')
            ->get();

        $totalUnbilledJam = round((float) $unbilledLogs->sum('jam_kerja'), 2);

        if ($totalUnbilledJam <= 0) {
            throw ValidationException::withMessages([
                'jam' => "Tidak ada jam kerja belum ditagih di kontrak {$contract->contract_number}.",
            ]);
        }

        $amount = round($totalUnbilledJam * (float) $contract->tarif_per_jam, 2);
        $invDate = $invoiceDate ? Carbon::parse($invoiceDate) : Carbon::today();
        $company = Company::findOrFail($contract->company_id);

        // BusinessUnit RENT
        $rentUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'RENT')
            ->first();

        return DB::transaction(function () use (
            $contract, $unbilledLogs, $totalUnbilledJam, $amount, $invDate, $rentUnit
        ) {
            $invoice = Invoice::create([
                'company_id'       => $contract->company_id,
                'invoice_number'   => 'DRAFT-' . now()->format('ymdHisu'),
                'invoice_date'     => $invDate,
                'due_date'         => $invDate->copy()->addDays(30),
                'client_id'        => $contract->client_id,
                'business_unit_id' => optional($rentUnit)->id,
                'description'      => sprintf(
                    'Sewa %s — %s jam @ Rp %s (%s)',
                    optional($contract->asset)->asset_code . ' ' . optional($contract->asset)->name,
                    rtrim(rtrim(number_format($totalUnbilledJam, 2, ',', '.'), '0'), ','),
                    number_format($contract->tarif_per_jam, 0, ',', '.'),
                    $contract->contract_number,
                ),
                'amount'           => $amount,
                'paid_amount'      => 0,
                'status'           => 'draft',
                'source_type'      => 'rental_contract',
                'source_id'        => $contract->id,
                'created_by'       => Auth::id() ?? $contract->created_by,
            ]);

            $invoice = $this->invoiceService->issue($invoice);

            // Link rental_logs ke invoice
            $unbilledLogs->each(function ($log) use ($invoice) {
                $log->update(['invoice_id' => $invoice->id]);
            });

            // Update billed_jam counter
            $contract->update([
                'billed_jam' => round((float) $contract->billed_jam + $totalUnbilledJam, 2),
            ]);

            return $invoice;
        });
    }

    /**
     * Tutup kontrak (status: aktif → selesai).
     */
    public function selesai(RentalContract $contract, ?CarbonInterface $endDate = null): RentalContract
    {
        if (! $contract->isAktif()) {
            throw ValidationException::withMessages([
                'status' => 'Kontrak sudah tidak aktif.',
            ]);
        }

        if ($contract->unbilled_jam > 0) {
            throw ValidationException::withMessages([
                'jam' => "Masih ada {$contract->unbilled_jam} jam belum ditagih. Tagih dulu sebelum tutup kontrak.",
            ]);
        }

        $contract->update([
            'status'   => 'selesai',
            'ended_at' => $endDate ? Carbon::parse($endDate) : Carbon::today(),
        ]);

        return $contract->refresh();
    }
}
