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
use Illuminate\Support\Facades\Log;
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

            // HPP posting (Tahap 5): tercatat baik untuk tunai maupun invoice.
            // Dijalankan setelah revenue posting agar log/urutan jurnal wajar.
            $this->postCogs($sale, $company, $matlUnit);

            return $sale->refresh();
        });
    }

    /**
     * Penjualan tunai → langsung jurnal Dr Kas Cr Pendapatan Material.
     */
    private function postTunai(MaterialSale $sale, Company $company, ?BusinessUnit $matlUnit): void
    {
        // Resolve akun kas: user pilih manual atau fallback ke 111100 (atau child-nya)
        if ($sale->cash_account_id) {
            $kasAccount = Account::withoutGlobalScopes()->find($sale->cash_account_id);
            if ($kasAccount && ! $kasAccount->isPostable()) {
                throw ValidationException::withMessages([
                    'cash_account_id' => "Akun [{$kasAccount->code}] {$kasAccount->name} adalah HEADER. Pilih sub-akun spesifik.",
                ]);
            }
        } else {
            $kasAccount = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);
        }

        if (! $kasAccount) {
            throw ValidationException::withMessages([
                'cash_account_id' => 'Akun Kas/Bank (111100) tidak ditemukan/postable. '
                    . 'Pastikan akun ini ada atau pilih akun kas manual.',
            ]);
        }

        // Sprint 2.5: role-based (revenue_matl) fallback code 441300
        $revenueAccount = Account::findByRoleOrCode(\App\Enums\AccountRole::RevenueMatl, '441300', $company->id);

        if (! $revenueAccount) {
            throw ValidationException::withMessages([
                'revenue' => 'Akun Pendapatan Penjualan Material (441300) tidak ditemukan/postable. '
                    . 'Tambahkan sub-akun bila sudah jadi HEADER.',
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

    /**
     * Post HPP (Cost of Goods Sold) untuk penjualan material.
     *
     * MVP (Business decision C1 disetujui 2026-07-06): asumsi simple —
     *   Dr 551300 Beban HPP Material   (volume × material.harga_pokok)
     *   Cr 111100 Kas                  (asumsi bayar tunai saat beli material)
     *
     * Ini menyederhanakan: kas naik dari penjualan (Dr Kas 165rb), turun dari
     * HPP (Cr Kas 100rb) → net kas = margin 65rb. Benar secara aritmatika.
     *
     * Batas:
     *   - Kalau material.harga_pokok = 0 → skip HPP, log warning. User perlu
     *     set harga_pokok di master material untuk aktifkan HPP posting.
     *   - Kalau material.harga_pokok > harga_satuan (jual rugi) → tetap post,
     *     laba kotor jadi negatif — reflek realita bisnis.
     *
     * Upgrade path (bukan sekarang): saat modul inventory + Purchase model
     * ada, ganti Cr Kas → Cr Persediaan Material Alam (111260) dengan
     * moving average cost dari pembelian real.
     */
    private function postCogs(MaterialSale $sale, Company $company, ?BusinessUnit $matlUnit): void
    {
        $hargaPokok = (float) $sale->material->harga_pokok;
        if ($hargaPokok <= 0) {
            // Business decision (2026-07-20): HPP OPTIONAL. Kalau kosong,
            // sale tetap sukses tapi jurnal HPP di-skip → laba kotor overstate.
            // Notifikasi warning ke log + Filament (jika di context UI) supaya
            // user tetap sadar. Ini opsi B (aware skip) — bukan silent skip lama.
            Log::warning(sprintf(
                'MaterialSale %s: HPP tidak posted karena material [%s] %s belum di-set harga_pokok. Laba kotor untuk sale ini akan overstate. Set harga_pokok di master material lalu manual re-post HPP kalau perlu akurasi.',
                $sale->sale_number,
                $sale->material->code,
                $sale->material->name,
            ));

            // Tampilkan Filament warning notification kalau ada UI context.
            // Sengaja tidak throw — sale sukses, user hanya diberitahu.
            if (class_exists(\Filament\Notifications\Notification::class) && app()->runningInConsole() === false) {
                try {
                    \Filament\Notifications\Notification::make()
                        ->title('HPP tidak posted')
                        ->body(sprintf(
                            'Material [%s] %s belum di-set Harga Pokok. Sale %s tetap sukses, tapi jurnal HPP di-skip — laba kotor untuk sale ini akan overstate. Set HPP di master material bila ingin laporan L/R lebih akurat.',
                            $sale->material->code,
                            $sale->material->name,
                            $sale->sale_number,
                        ))
                        ->warning()
                        ->persistent()
                        ->send();
                } catch (\Throwable) {
                    // ignore — notification bisa gagal di background queue / test env
                }
            }

            return;
        }

        $totalHpp = round((float) $sale->volume * $hargaPokok, 2);
        if ($totalHpp <= 0) return;

        // Sprint 2.5: role-based
        $accHpp = Account::findByRoleOrCode(\App\Enums\AccountRole::CogsMaterial, '551300', $company->id);
        $accKas = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);

        if (! $accHpp || ! $accKas) {
            Log::warning("MaterialSaleService::postCogs: akun 551300 atau 111100 tidak ditemukan/postable untuk company {$company->id}. Skip HPP {$sale->sale_number}.");
            return;
        }

        $saleDate = Carbon::parse($sale->sale_date);

        $journal = JournalEntry::create([
            'company_id'       => $sale->company_id,
            'entry_number'     => $this->journalService->generateEntryNumber($company, $saleDate),
            'entry_date'       => $saleDate,
            'document_number'  => 'HPP-' . $sale->sale_number,
            'document_type'    => 'jual_beli',
            'business_unit_id' => optional($matlUnit)->id,
            'description'      => 'HPP penjualan material ' . $sale->material->name
                . ' ' . rtrim(rtrim((string) $sale->volume, '0'), '.') . ' ' . $sale->material->satuan
                . ' — ' . $sale->client->name,
            'period_year'      => $saleDate->year,
            'period_month'     => $saleDate->month,
            'status'           => 'posted',
            'created_by'       => Auth::id() ?? $sale->created_by,
            'posted_by'        => Auth::id() ?? $sale->created_by,
            'posted_at'        => now(),
            'total_amount'     => $totalHpp,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id'       => $accHpp->id,
            'description'      => 'HPP ' . $sale->material->name . ' × ' . rtrim(rtrim((string) $sale->volume, '0'), '.') . ' ' . $sale->material->satuan,
            'debit'            => $totalHpp,
            'kredit'           => 0,
            'sort_order'       => 1,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id'       => $accKas->id,
            'description'      => 'Pembayaran material (asumsi tunai — MVP tanpa inventory)',
            'debit'            => 0,
            'kredit'           => $totalHpp,
            'sort_order'       => 2,
        ]);
    }
}
