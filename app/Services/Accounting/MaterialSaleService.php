<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Material;
use App\Models\MaterialSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialSaleService
{
    public function __construct(
        private JournalService $journalService,
        private InvoiceService $invoiceService,
    ) {}

    /**
     * Auto-generate nomor penjualan per company per bulan.
     * Format: PJ{YY}{MM}-{NNNN}, contoh: PJ2606-0001
     */
    public function generateSaleNumber(Company $company, CarbonInterface $date): string
    {
        $prefix = sprintf('PJ%02d%02d-', $date->format('y'), $date->format('m'));

        $lastNumber = MaterialSale::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('sale_number', 'like', $prefix . '%')
            ->orderByDesc('sale_number')
            ->value('sale_number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Buat penjualan material. Auto-handle 2 alur:
     *   - tunai   → langsung jurnal Dr Kas / Cr Pendapatan Material
     *   - invoice → bikin Invoice (status terbit, auto-issue)
     */
    public function create(array $data): MaterialSale
    {
        $material = Material::findOrFail($data['material_id']);
        $client   = Client::findOrFail($data['client_id']);
        $volume   = (float) $data['volume'];
        $harga    = (float) ($data['harga_satuan'] ?? $material->harga_per_satuan);
        $total    = round($volume * $harga, 2);
        $saleDate = Carbon::parse($data['sale_date'] ?? today());
        $metode   = $data['metode'] ?? 'tunai';
        $company  = Company::findOrFail($material->company_id);

        if ($volume <= 0) {
            throw ValidationException::withMessages(['volume' => 'Volume harus lebih dari 0.']);
        }

        $this->journalService->assertPeriodOpen($company, $saleDate->year, $saleDate->month);

        // BusinessUnit MATL (untuk tag jurnal)
        $matlUnit = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'MATL')
            ->first();

        return DB::transaction(function () use (
            $data, $material, $client, $volume, $harga, $total, $saleDate,
            $metode, $company, $matlUnit
        ) {
            $sale = MaterialSale::create([
                'company_id'   => $company->id,
                'sale_number'  => $this->generateSaleNumber($company, $saleDate),
                'sale_date'    => $saleDate,
                'client_id'    => $client->id,
                'material_id'  => $material->id,
                'volume'       => $volume,
                'harga_satuan' => $harga,
                'total'        => $total,
                'metode'       => $metode,
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'created_by'   => Auth::id(),
            ]);

            if ($metode === 'tunai') {
                $this->postTunai($sale, $company, $matlUnit);
            } else {
                $this->postInvoice($sale, $matlUnit);
            }

            return $sale->refresh();
        });
    }

    /**
     * Penjualan tunai → langsung jurnal Dr Kas Cr Pendapatan Material.
     */
    private function postTunai(MaterialSale $sale, Company $company, ?BusinessUnit $matlUnit): void
    {
        // Resolve akun kas (default 111100)
        $kasAccount = $sale->cash_account_id
            ? Account::withoutGlobalScopes()->find($sale->cash_account_id)
            : Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', '111100')
                ->first();

        if (! $kasAccount) {
            throw ValidationException::withMessages([
                'cash_account_id' => 'Akun Kas/Bank tidak ditemukan. Pilih akun kas manual.',
            ]);
        }

        // Resolve akun pendapatan material (441300)
        $revenueAccount = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', '441300')
            ->first();

        if (! $revenueAccount) {
            throw ValidationException::withMessages([
                'revenue' => 'Akun Pendapatan Penjualan Material (441300) tidak ditemukan di COA.',
            ]);
        }

        $saleDate = Carbon::parse($sale->sale_date);

        $journal = JournalEntry::create([
            'company_id'       => $sale->company_id,
            'entry_number'     => $this->journalService->generateEntryNumber($company, $saleDate),
            'entry_date'       => $saleDate,
            'document_number'  => $sale->sale_number,
            'document_type'    => 'bkm',
            'business_unit_id' => optional($matlUnit)->id,
            'description'      => 'Penjualan tunai ' . $sale->material->name
                . ' ' . rtrim(rtrim((string) $sale->volume, '0'), '.') . ' ' . $sale->material->satuan
                . ' — ' . $sale->client->name,
            'period_year'      => $saleDate->year,
            'period_month'     => $saleDate->month,
            'status'           => 'posted',
            'created_by'       => Auth::id() ?? $sale->created_by,
            'posted_by'        => Auth::id() ?? $sale->created_by,
            'posted_at'        => now(),
            'total_amount'     => $sale->total,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id'       => $kasAccount->id,
            'description'      => 'Penerimaan tunai penjualan material',
            'debit'            => $sale->total,
            'kredit'           => 0,
            'sort_order'       => 1,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id'       => $revenueAccount->id,
            'description'      => 'Pendapatan ' . $sale->material->name,
            'debit'            => 0,
            'kredit'           => $sale->total,
            'sort_order'       => 2,
        ]);

        $sale->update(['journal_entry_id' => $journal->id]);
    }

    /**
     * Penjualan invoice → bikin Invoice + auto-issue (jurnal Dr Piutang Cr Pendapatan).
     */
    private function postInvoice(MaterialSale $sale, ?BusinessUnit $matlUnit): void
    {
        // Bikin invoice draft
        $invoice = Invoice::create([
            'company_id'       => $sale->company_id,
            'invoice_number'   => 'DRAFT-' . now()->format('ymdHisu'),
            'invoice_date'     => $sale->sale_date,
            'due_date'         => Carbon::parse($sale->sale_date)->addDays(30),
            'client_id'        => $sale->client_id,
            'business_unit_id' => optional($matlUnit)->id,
            'description'      => $sale->material->name
                . ' ' . rtrim(rtrim((string) $sale->volume, '0'), '.') . ' ' . $sale->material->satuan
                . ' @ ' . 'Rp ' . number_format($sale->harga_satuan, 0, ',', '.')
                . ' (' . $sale->sale_number . ')',
            'amount'           => $sale->total,
            'paid_amount'      => 0,
            'status'           => 'draft',
            'source_type'      => 'material_sale',
            'source_id'        => $sale->id,
            'created_by'       => Auth::id() ?? $sale->created_by,
        ]);

        // Auto-issue
        $this->invoiceService->issue($invoice);

        $sale->update(['invoice_id' => $invoice->id]);
    }
}
