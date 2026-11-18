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
use App\Support\DocumentSequence;
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
     * True selama reverseSale() berjalan — JournalEntryObserver memakainya untuk
     * membedakan void HPP/KIRIM oleh sistem dari void manual di Jurnal Umum.
     */
    public static bool $reversing = false;

    /**
     * Batalkan penjualan TUNAI: void jurnal penjualan (Dr Kas / Cr Pendapatan),
     * kembalikan stok, balik HPP & biaya kirim. Penjualan tetap tersimpan
     * dengan penanda batal (jejak audit). Penjualan invoice dibatalkan dengan
     * me-void invoicenya.
     */
    public function voidTunai(MaterialSale $sale, \App\Models\User $user, string $reason): MaterialSale
    {
        if (! $sale->isTunai()) {
            throw ValidationException::withMessages(['sale' => 'Penjualan dengan invoice dibatalkan lewat Void invoice-nya di Tagihan & Piutang.']);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan pembatalan.']);
        }

        return DB::transaction(function () use ($sale, $user, $reason) {
            $locked = MaterialSale::withoutGlobalScopes()->lockForUpdate()->findOrFail($sale->id);
            if ($locked->isVoided()) {
                throw ValidationException::withMessages(['sale' => "Penjualan {$locked->sale_number} sudah dibatalkan."]);
            }
            // Tandai dulu → JournalEntryObserver tidak memproses ulang saat jurnalnya di-void.
            $locked->update(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => mb_substr(trim($reason), 0, 255)]);

            $journal = $locked->journal_entry_id ? JournalEntry::withoutGlobalScopes()->find($locked->journal_entry_id) : null;
            if ($journal && $journal->isPosted()) {
                $this->journalService->void($journal, "Batal penjualan {$locked->sale_number}: {$reason}");
            }
            $this->reverseSale($locked, "Batal penjualan {$locked->sale_number}");

            return $locked->refresh();
        });
    }

    /**
     * Kembalikan stok (bila penjualan tercatat keluar stok), void jurnal HPP &
     * biaya kirim. Dipakai void invoice penjualan (InvoiceObserver), batal
     * penjualan tunai, dan void manual jurnal penjualan tunai.
     */
    public function reverseSale(MaterialSale $sale, string $label): void
    {
        self::$reversing = true;
        try {
            $outMovement = MaterialStockMovement::withoutGlobalScopes()
                ->where('source_type', MaterialSale::class)
                ->where('source_id', $sale->id)
                ->where('movement_type', 'out')
                ->first();
            $alreadyRestored = MaterialStockMovement::withoutGlobalScopes()
                ->where('source_type', MaterialSale::class)
                ->where('source_id', $sale->id)
                ->where('movement_type', 'adjustment')
                ->exists();

            // Penjualan legacy (tanpa stock movement) dilewati. Invoice yang
            // di-void dua kali (sale ditagih ulang lalu void lagi) tidak boleh
            // mengembalikan stok dua kali.
            if ($outMovement && ! $alreadyRestored) {
                $material = Material::withoutGlobalScopes()->lockForUpdate()->find($sale->material_id);
                if ($material) {
                    $stockBefore = (float) $material->current_stock;
                    $qtyRestore  = abs((float) $outMovement->qty_change);
                    $stockAfter  = $stockBefore + $qtyRestore;
                    $macBefore   = (float) $material->current_mac;
                    // Barang kembali senilai HPP-nya (biaya saat keluar), bukan MAC hari ini:
                    // jurnal HPP dibalik sebesar qty × biaya lama, jadi MAC dihitung ulang
                    // sebagai rata-rata tertimbang supaya stok × MAC = saldo Persediaan.
                    $macAfter = $stockAfter > 0
                        ? round(($stockBefore * $macBefore + $qtyRestore * (float) $outMovement->unit_cost) / $stockAfter, 4)
                        : $macBefore;

                    DB::table('materials')->where('id', $material->id)
                        ->update(['current_stock' => $stockAfter, 'current_mac' => $macAfter, 'updated_at' => now()]);

                    MaterialStockMovement::create([
                        'company_id'    => $material->company_id,
                        'material_id'   => $material->id,
                        'movement_type' => 'adjustment',
                        'source_type'   => MaterialSale::class,
                        'source_id'     => $sale->id,
                        'qty_change'    => $qtyRestore,
                        'unit_cost'     => (float) $outMovement->unit_cost,
                        'stock_before'  => $stockBefore,
                        'stock_after'   => $stockAfter,
                        'mac_before'    => $macBefore,
                        'mac_after'     => $macAfter,
                        'movement_date' => now()->toDateString(),
                        'notes'         => "Restore stok — {$label}",
                        'created_by'    => Auth::id(),
                    ]);
                }
            }

            // HPP (source = sale, atau data lama No. Bukti HPP-{no}) & biaya kirim (source = sale).
            $journals = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $sale->company_id)
                ->where('status', 'posted')
                ->where(function ($q) use ($sale) {
                    $q->where(fn ($s) => $s->where('source_type', $sale->getMorphClass())->where('source_id', $sale->id))
                      ->orWhere(fn ($s) => $s->whereNull('source_type')
                          ->where('document_type', 'jual_beli')
                          ->where('document_number', 'HPP-' . $sale->sale_number));
                })
                ->get();
            foreach ($journals as $journal) {
                $this->journalService->void($journal, $label);
            }
        } finally {
            self::$reversing = false;
        }
    }

    /**
     * Auto-generate nomor penjualan per company per bulan.
     * Format: PJ{YY}{MM}-{NNNN}, contoh: PJ2606-0001
     */
    public function generateSaleNumber(Company $company, CarbonInterface $date): string
    {
        $prefix = sprintf('PJ%02d%02d-', $date->format('y'), $date->format('m'));

        return DocumentSequence::next(
            MaterialSale::withoutGlobalScopes()->where('company_id', $company->id),
            'sale_number',
            $prefix,
            4,
        );
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
        $saleDate = Carbon::parse($data['sale_date'] ?? today());
        $metode   = $data['metode'] ?? 'tunai';
        $company  = Company::findOrFail($material->company_id);

        // Jual per RIT (masukan atasan 2026-11-18): harga per rit × jumlah rit.
        // Volume tetap wajib = jumlah keluar dari stok (satuan material).
        $satuanJual = ($data['satuan_jual'] ?? 'satuan') === 'rit' ? 'rit' : 'satuan';
        $jumlahRit  = (float) ($data['jumlah_rit'] ?? 0);
        if ($satuanJual === 'rit' && $jumlahRit <= 0) {
            throw ValidationException::withMessages(['jumlah_rit' => 'Jumlah rit wajib diisi untuk penjualan per rit.']);
        }
        $total = round(($satuanJual === 'rit' ? $jumlahRit : $volume) * $harga, 2);

        if ($volume <= 0) {
            throw ValidationException::withMessages(['volume' => 'Volume harus lebih dari 0.']);
        }

        $delivery = $this->normalizeDelivery($data, $company, $jumlahRit);

        // Sparepart dipakai sendiri saat servis unit, bukan dijual ke klien.
        // Guard di service (bukan cuma filter dropdown) — form bisa dilewati.
        if ($material->isSparepart()) {
            throw ValidationException::withMessages([
                'material_id' => sprintf(
                    '[%s] %s berjenis Sparepart — dipakai sendiri saat servis, tidak dijual ke klien. '
                    . 'Catat pemakaiannya di Armada & Alat Berat → tab Pemeliharaan.',
                    $material->code,
                    $material->name,
                ),
            ]);
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
            $metode, $company, $matlUnit, $satuanJual, $jumlahRit, $delivery
        ) {
            // Cek ulang stok SETELAH lock: dua penjualan bersamaan dulu sama-sama
            // lolos cek di atas, stok terpotong ke 0 diam-diam, HPP tetap penuh.
            $lockedStock = (float) Material::withoutGlobalScopes()->lockForUpdate()->findOrFail($material->id)->current_stock;
            if ($volume > $lockedStock + 0.0001 && MaterialStockMovement::withoutGlobalScopes()->where('material_id', $material->id)->exists()) {
                throw ValidationException::withMessages([
                    'volume' => sprintf('Stok %s tidak cukup (baru saja terpakai penjualan lain). Tersedia: %s %s.',
                        $material->name, rtrim(rtrim(number_format($lockedStock, 2, ',', '.'), '0'), ','), $material->satuan),
                ]);
            }

            $sale = MaterialSale::create($delivery + [
                'company_id'   => $company->id,
                'sale_number'  => $this->generateSaleNumber($company, $saleDate),
                'sale_date'    => $saleDate,
                'client_id'    => $client->id,
                'material_id'  => $material->id,
                'volume'       => $volume,
                'satuan_jual'  => $satuanJual,
                'jumlah_rit'   => $jumlahRit > 0 ? $jumlahRit : null,
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

            // Biaya pengiriman armada (ongkos supir, UM, BBM, BM, portal, lain-lain).
            $this->postDeliveryCosts($sale->refresh(), $company, $matlUnit);

            return $sale->refresh();
        });
    }

    /**
     * Bersihkan isian pengiriman: angka ≥ 0, truk/supir/kas milik PT ini.
     *
     * @return array<string, mixed> kolom material_sales
     */
    private function normalizeDelivery(array $data, Company $company, float $jumlahRit): array
    {
        $money = fn (string $k) => max(0, round((float) ($data[$k] ?? 0)));

        $out = [
            'asset_id'             => null,
            'driver_id'            => null,
            'ongkos_supir_per_rit' => $money('ongkos_supir_per_rit'),
            'uang_makan_supir'     => $money('uang_makan_supir'),
            'biaya_bbm'            => $money('biaya_bbm'),
            'bm_per_rit'           => $money('bm_per_rit'),
            'biaya_portal'         => $money('biaya_portal'),
            'biaya_lain'           => $money('biaya_lain'),
            'biaya_lain_ket'       => isset($data['biaya_lain_ket']) ? mb_substr((string) $data['biaya_lain_ket'], 0, 150) : null,
            'biaya_cash_account_id' => null,
        ];

        // Guard tenant di service (form bisa dilewati): id dari PT lain ditolak.
        if (! empty($data['asset_id'])) {
            $out['asset_id'] = \App\Models\Asset::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($data['asset_id'])->value('id')
                ?? throw ValidationException::withMessages(['asset_id' => 'Truk tidak ditemukan di PT ini.']);
        }
        if (! empty($data['driver_id'])) {
            $out['driver_id'] = \App\Models\Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($data['driver_id'])->value('id')
                ?? throw ValidationException::withMessages(['driver_id' => 'Supir tidak ditemukan di PT ini.']);
        }

        $perRit = $out['ongkos_supir_per_rit'] + $out['bm_per_rit'];
        if ($perRit > 0 && $jumlahRit <= 0) {
            throw ValidationException::withMessages([
                'jumlah_rit' => 'Isi jumlah rit — ongkos supir & BM dihitung per rit.',
            ]);
        }

        $total = round($jumlahRit * $perRit + $out['uang_makan_supir'] + $out['biaya_bbm'] + $out['biaya_portal'] + $out['biaya_lain'], 2);
        $out['biaya_kirim_total'] = $total;

        if ($total > 0) {
            $cash = ! empty($data['biaya_cash_account_id'])
                ? Account::withoutGlobalScopes()->where('company_id', $company->id)->find($data['biaya_cash_account_id'])
                : (! empty($data['cash_account_id'])
                    ? Account::withoutGlobalScopes()->where('company_id', $company->id)->find($data['cash_account_id'])
                    : Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id));

            if (! $cash || ! $cash->isPostable()) {
                throw ValidationException::withMessages([
                    'biaya_cash_account_id' => 'Pilih akun kas/bank (sub-akun) untuk membayar biaya pengiriman.',
                ]);
            }
            $out['biaya_cash_account_id'] = $cash->id;
        }

        return $out;
    }

    /**
     * Jurnal biaya pengiriman: Dr beban per komponen (sesuai Peran akun) /
     * Cr Kas. Ditandai lini MATL + truk pengantar (Laba Rugi per Unit).
     */
    private function postDeliveryCosts(MaterialSale $sale, Company $company, ?BusinessUnit $matlUnit): void
    {
        if ((float) $sale->biaya_kirim_total <= 0) {
            return;
        }

        $lines = [];
        foreach ($sale->deliveryCostBreakdown() as $key => $amount) {
            if ($amount <= 0) {
                continue;
            }
            [$label, $role, $code] = MaterialSale::DELIVERY_COSTS[$key];
            $account = Account::findByRoleOrCode($role, $code, $company->id)
                ?? throw ValidationException::withMessages([
                    'biaya_kirim' => "Akun beban untuk \"{$label}\" tidak ditemukan. Beri Peran \"{$role->label()}\" di Daftar Akun.",
                ]);

            $desc = $label . ($key === 'biaya_lain' && $sale->biaya_lain_ket ? " ({$sale->biaya_lain_ket})" : '')
                . ($key === 'ongkos_supir' || $key === 'bm'
                    ? ' ' . rtrim(rtrim(number_format((float) $sale->jumlah_rit, 2, ',', '.'), '0'), ',') . ' rit'
                    : '');

            $lines[] = [
                'account_id'  => $account->id,
                'asset_id'    => $sale->asset_id,
                'description' => "{$desc} — {$sale->sale_number}",
                'debit'       => $amount,
                'kredit'      => 0,
            ];
        }

        $lines[] = [
            'account_id'  => $sale->biaya_cash_account_id,
            'description' => "Biaya pengiriman {$sale->sale_number}",
            'debit'       => 0,
            'kredit'      => (float) $sale->biaya_kirim_total,
        ];

        $date = Carbon::parse($sale->sale_date);
        $journal = $this->journalService->createEntryWithLines(
            company:          $company,
            date:             $date,
            entryDataFactory: fn (string $entryNumber): array => [
                'company_id'       => $company->id,
                'entry_number'     => $entryNumber,
                'entry_date'       => $date,
                'document_number'  => 'KIRIM-' . $sale->sale_number,
                'document_type'    => 'bkk',
                'source_type'      => $sale->getMorphClass(),
                'source_id'        => $sale->id,
                'business_unit_id' => optional($matlUnit)->id,
                'description'      => "Biaya pengiriman {$sale->material->name} ke {$sale->client->name}",
                'period_year'      => $date->year,
                'period_month'     => $date->month,
                'status'           => 'posted',
                'created_by'       => Auth::id() ?? $sale->created_by,
                'posted_by'        => Auth::id() ?? $sale->created_by,
                'posted_at'        => now(),
                'total_amount'     => (float) $sale->biaya_kirim_total,
            ],
            linesFactory: fn () => $lines,
        );

        $sale->update(['biaya_journal_id' => $journal->id]);
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
                    . ' ' . $sale->quantityLabel()
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
                . ' ' . $sale->quantityLabel()
                . ' @ ' . 'Rp ' . number_format($sale->harga_satuan, 0, ',', '.')
                . ($sale->satuan_jual === 'rit' ? '/rit' : '')
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
     * Kalau MAC tersedia (sudah ada pembelian), kredit ke akun Persediaan
     * Material (Account::materialInventoryAccount). Tanpa MAC (data legacy),
     * tetap kredit Kas seperti perilaku lama.
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
            // Resolusi SAMA dengan debit di MaterialPurchaseService — persediaan
            // yang dikredit harus akun yang dulu didebit saat pembelian.
            $accKredit = Account::inventoryAccountFor($lockedMaterial);
        } else {
            $accKredit = Account::findByRoleOrCode(\App\Enums\AccountRole::Cash, '111100', $company->id);
        }

        // Dulu: Log::warning lalu return — penjualan & pendapatan tercatat, tapi
        // HPP tidak dijurnal DAN stok tidak berkurang (blok update stok ada di
        // bawah). Stok & persediaan jadi lebih besar dari kenyataan tanpa ada
        // yang tahu. Sekarang ditolak (dalam transaksi → seluruh penjualan batal).
        if (! $accHpp || ! $accKredit) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'material_id' => 'Akun HPP Material atau Persediaan Material tidak ditemukan/postable. '
                    . 'Beri Peran "' . \App\Enums\AccountRole::CogsMaterial->label() . '" dan "'
                    . \App\Enums\AccountRole::InventoryMaterial->label() . '" di Daftar Akun, lalu ulangi.',
            ]);
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
                // Link struktural: void invoice penjualan membalik jurnal HPP ini
                // (InvoiceObserver::detachMaterialSale).
                'source_type'      => $sale->getMorphClass(),
                'source_id'        => $sale->id,
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

        // HPP per penjualan — dasar laporan margin.
        $sale->update(['hpp_total' => $totalHpp]);

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
