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
use App\Models\MaterialStockMovement;
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

        // BIZ-01: Stock guard — cegah sale kalau stok < qty diminta.
        // Backward-compat: material yang belum PERNAH ada stock movement =
        // legacy (tidak track inventory) → allow sale, HPP fallback ke
        // harga_pokok statis di postCogs().
        // Kalau material sudah ada history purchase/adjustment → strict guard.
        $currentStock = (float) $material->current_stock;
        if ($volume > $currentStock) {
            $hasMovements = MaterialStockMovement::withoutGlobalScopes()
                ->where('material_id', $material->id)
                ->exists();

            if ($hasMovements) {
                throw ValidationException::withMessages([
                    'volume' => sprintf(
                        'Stok %s tidak cukup. Tersedia: %s %s, diminta: %s %s. '
                        . 'Input pembelian material dulu di menu Master Data → Pembelian Material.',
                        $material->name,
                        rtrim(rtrim(number_format($currentStock, 2, ',', '.'), '0'), ','),
                        $material->satuan,
                        rtrim(rtrim(number_format($volume, 2, ',', '.'), '0'), ','),
                        $material->satuan,
                    ),
                ]);
            }
            // else: material legacy tanpa inventory tracking → allow, fallback ke harga_pokok statis
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
                'bukti_tf_path' => $data['bukti_tf_path'] ?? null,
                // BUG-27: fallback ke $data['created_by'] untuk CLI/queue context
                //         (Auth::id() null di luar HTTP request).
                'created_by'   => Auth::id() ?? ($data['created_by'] ?? 1),
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

        // BUG-11: Refactor pakai createEntryWithLines
        $journal = $this->journalService->createEntryWithLines(
            company:          $company,
            date:             $saleDate,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $sale->company_id,
                'entry_number'     => $entryNumber,
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
            ],
            linesFactory:     fn (JournalEntry $entry): array => [
                [
                    'account_id'  => $kasAccount->id,
                    'description' => 'Penerimaan tunai penjualan material',
                    'debit'       => $sale->total,
                    'kredit'      => 0,
                ],
                [
                    'account_id'  => $revenueAccount->id,
                    'description' => 'Pendapatan ' . $sale->material->name,
                    'debit'       => 0,
                    'kredit'      => $sale->total,
                ],
            ],
        );

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
        // BIZ-01: Prefer current_mac (Moving Average Cost dari purchase real).
        // Fallback ke harga_pokok statis kalau MAC = 0 (mis. material yang belum
        // pernah ada purchase — data legacy).
        // Lock material row untuk race-safe stock update.
        $lockedMaterial = Material::withoutGlobalScopes()
            ->where('id', $sale->material_id)
            ->lockForUpdate()
            ->first();

        $macCost = (float) $lockedMaterial->current_mac;
        $hargaPokok = $macCost > 0
            ? $macCost
            : (float) $sale->material->harga_pokok;

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

        // BIZ-01: Kalau MAC dipakai (ada purchase), credit ke Persediaan Material.
        // Kalau fallback ke harga_pokok statis (legacy — tidak ada purchase),
        // tetap credit ke Kas (backward compat asumsi lama "beli material tunai").
        $usingMac = $macCost > 0;
        if ($usingMac) {
            $accKredit = Account::findByRoleOrCode(
                \App\Enums\AccountRole::InventorySolar,
                '111260',
                $company->id,
            ) ?? Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', '111220')
                ->postable()
                ->first();
        } else {
            $accKredit = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);
        }

        if (! $accHpp || ! $accKredit) {
            Log::warning("MaterialSaleService::postCogs: akun HPP (551300) atau credit account tidak ditemukan/postable untuk company {$company->id}. Skip HPP {$sale->sale_number}.");
            return;
        }

        $saleDate = Carbon::parse($sale->sale_date);

        // BUG-11: Refactor pakai createEntryWithLines
        $this->journalService->createEntryWithLines(
            company:          $company,
            date:             $saleDate,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $sale->company_id,
                'entry_number'     => $entryNumber,
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
            ],
            linesFactory:     fn (JournalEntry $entry): array => [
                [
                    'account_id'  => $accHpp->id,
                    'description' => 'HPP ' . $sale->material->name . ' × ' . rtrim(rtrim((string) $sale->volume, '0'), '.') . ' ' . $sale->material->satuan . ($usingMac ? ' @ MAC Rp ' . number_format($hargaPokok, 2, ',', '.') : ' (harga_pokok statis)'),
                    'debit'       => $totalHpp,
                    'kredit'      => 0,
                ],
                [
                    'account_id'  => $accKredit->id,
                    'description' => $usingMac
                        ? 'Persediaan ' . $sale->material->name . ' berkurang ' . rtrim(rtrim((string) $sale->volume, '0'), '.') . ' ' . $sale->material->satuan
                        : 'Pembayaran material (asumsi tunai — data legacy tanpa purchase)',
                    'debit'       => 0,
                    'kredit'      => $totalHpp,
                ],
            ],
        );

        // BIZ-01: Update stock + record movement OUT (hanya kalau pakai MAC).
        // Legacy sale (harga_pokok statis) tidak update stock karena tidak
        // ada purchase yang naikkan stock sebelumnya — asumsi lama beli-jual
        // instan tanpa inventory.
        if ($usingMac) {
            $stockBefore = (float) $lockedMaterial->current_stock;
            $stockAfter  = max(0, $stockBefore - (float) $sale->volume);

            DB::table('materials')
                ->where('id', $lockedMaterial->id)
                ->update([
                    'current_stock' => $stockAfter,
                    // MAC tidak berubah saat sale (hanya berubah saat purchase)
                    'updated_at'    => now(),
                ]);

            MaterialStockMovement::create([
                'company_id'   => $company->id,
                'material_id'  => $lockedMaterial->id,
                'movement_type'=> 'out',
                'source_type'  => MaterialSale::class,
                'source_id'    => $sale->id,
                'qty_change'   => -1 * (float) $sale->volume,
                'unit_cost'    => $hargaPokok,
                'stock_before' => $stockBefore,
                'stock_after'  => $stockAfter,
                'mac_before'   => (float) $lockedMaterial->current_mac,
                'mac_after'    => (float) $lockedMaterial->current_mac,
                'movement_date'=> $saleDate,
                'notes'        => 'Penjualan ' . $sale->sale_number,
                'created_by'   => Auth::id() ?? $sale->created_by,
            ]);
        }
    }
}
