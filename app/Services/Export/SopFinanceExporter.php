<?php

namespace App\Services\Export;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backup data keuangan MY-TRUCK ke file "SOP Finance - Armada.xlsx".
 * Prinsip umum (angka & nomor MY-TRUCK, hanya kolom input) — lihat
 * SopWorkbookExporter.
 *
 * Kenapa per periode (DARI–SAMPAI), bukan seluruh riwayat:
 *   Template hanya menjangkau 10.000 baris JURNAL. Jurnal sebelum DARI
 *   diringkas jadi SALDO AWAL di COA — persis cara sheet NRL menghitung
 *   saldo awal (COA saldo awal + jurnal sebelum NRL!E3), jadi hasilnya sama.
 *
 * Konsisten dengan laporan MY-TRUCK:
 *   - Jurnal 'void' & 'pembalik' tidak diekspor (lihat TrialBalanceService).
 *   - Jurnal 'saldo_awal' masuk ke saldo awal COA, bukan JURNAL.
 *
 * Sheet jembatan "Master Unit" (IMPORTRANGE dari file Aset) diisi salinan
 * statis supaya LR per Unit & dropdown Kode Unit di JURNAL hidup offline.
 */
class SopFinanceExporter extends SopWorkbookExporter
{
    // Rentang baris yang dijangkau rumus template. Menulis di luar rentang
    // ini = datanya diam-diam tidak ikut terhitung, jadi export ditolak.
    public const COA_FIRST_ROW        = 6;
    public const COA_LAST_ROW         = 205;
    public const MASTER_FIRST_ROW     = self::FIRST_ROW;
    public const KLIEN_LAST_ROW       = 511;
    public const VENDOR_LAST_ROW      = 511;
    public const TAGIHAN_LAST_ROW     = 2011;
    public const PEMBAYARAN_LAST_ROW  = 3011;
    public const JURNAL_FIRST_ROW     = 8;
    public const JURNAL_LAST_ROW      = 10007;

    public function __construct(private SopMasterData $master) {}

    public function file(): SopFile
    {
        return SopFile::Finance;
    }

    /** Isi dropdown Parameter!CA7:CA36 (Kategori Arus Kas). */
    private const ARUS_KAS = [
        'operasi'   => 'Operasi',
        'investasi' => 'Investasi',
        'pendanaan' => 'Pendanaan',
    ];
    private const ARUS_KAS_TRANSFER = 'Transfer Antar Kas';

    public function buildPayload(
        Company $company,
        CarbonInterface $dari,
        CarbonInterface $sampai,
        ?string $exportedBy = null,
    ): array {
        [$dari, $sampai, $dariStr, $untilStr] = $this->period($dari, $sampai);

        $warnings = [];
        $cid      = $company->id;
        $cells    = array_fill_keys(
            ['COA', 'Master Klien', 'Master Vendor', 'JURNAL', 'Tagihan', 'Pembayaran', 'Parameter', 'NRL', 'Dashboard', 'Master Unit'],
            [],
        );

        // ── Akun ────────────────────────────────────────────────────────
        $accounts = $this->accountsForCoa($cid);
        $this->assertCapacity('COA (akun)', $accounts->count(), self::COA_LAST_ROW, self::COA_FIRST_ROW);

        $cashIds = array_flip(Account::cashAccountIds($cid));
        $types   = $accounts->mapWithKeys(fn (Account $a) => [$a->id => $this->sopType($a, isset($cashIds[$a->id]))]);
        $names   = $this->uniqueAccountNames($accounts, $warnings);

        // ── Buku pembantu: jurnal → klien / vendor ──────────────────────
        $clients = $this->master->clients($cid)->keyBy('id');
        $vendors = $this->master->vendors($cid)->keyBy('id');
        $this->assertCapacity('Master Klien', $clients->count(), self::KLIEN_LAST_ROW);
        $this->assertCapacity('Master Vendor', $vendors->count(), self::VENDOR_LAST_ROW);

        [$clientByJournal, $vendorByJournal] = $this->partyByJournal($cid);

        // ── Saldo awal per akun & per klien/vendor ──────────────────────
        $openingLines = $this->postedLines($cid)
            ->where(function ($q) use ($dariStr, $untilStr) {
                $q->where('je.entry_date', '<', $dariStr)
                  ->orWhere(fn ($q2) => $q2
                      ->where('je.document_type', 'saldo_awal')
                      ->where('je.entry_date', '<', $untilStr));
            })
            ->groupBy('je.id', 'jl.account_id')
            ->select('je.id as je_id', 'jl.account_id', DB::raw('SUM(jl.debit) as d'), DB::raw('SUM(jl.kredit) as k'))
            ->get();

        $openingByAccount = [];
        $openingByClient  = [];
        $openingByVendor  = [];
        foreach ($openingLines as $row) {
            $net = (float) $row->d - (float) $row->k;
            $openingByAccount[$row->account_id] = ($openingByAccount[$row->account_id] ?? 0) + $net;

            $type = $types[$row->account_id] ?? null;
            if ($type === 'Piutang' && isset($clientByJournal[$row->je_id])) {
                $id = $clientByJournal[$row->je_id];
                $openingByClient[$id] = ($openingByClient[$id] ?? 0) + $net;
            } elseif ($type === 'Hutang Usaha' && isset($vendorByJournal[$row->je_id])) {
                $id = $vendorByJournal[$row->je_id];
                $openingByVendor[$id] = ($openingByVendor[$id] ?? 0) - $net; // utang: saldo normal kredit
            }
        }

        $clear = [
            // Template berisi contoh COA — dikosongkan dulu. Kolom rumus (G–I, K–O) dibiarkan.
            'COA' => [
                'B' . self::COA_FIRST_ROW . ':F' . self::COA_LAST_ROW,
                'J' . self::COA_FIRST_ROW . ':J' . self::COA_LAST_ROW,
            ],
        ];

        // ── Sheet COA ───────────────────────────────────────────────────
        $row = self::COA_FIRST_ROW;
        foreach ($accounts as $a) {
            $net = round($openingByAccount[$a->id] ?? 0, 2);
            $cells['COA']["B{$row}"] = $this->codeValue($a->code);
            $cells['COA']["C{$row}"] = $names[$a->id];
            $cells['COA']["D{$row}"] = $types[$a->id];
            $cells['COA']["E{$row}"] = $net > 0 ? $net : null;
            $cells['COA']["F{$row}"] = $net < 0 ? -$net : null;
            $cells['COA']["J{$row}"] = $a->role instanceof AccountRole ? $a->role->label() : null;
            $row++;
        }

        // ── Master Klien & Vendor ───────────────────────────────────────
        $row = self::MASTER_FIRST_ROW;
        foreach ($clients as $c) {
            $saldo = round($openingByClient[$c->id] ?? 0, 2);
            $cells['Master Klien'] += [
                "C{$row}" => (string) $c->code,   // timpa rumus KL-NNN: kode MY-TRUCK
                "D{$row}" => $c->name,
                "E{$row}" => $c->contact_person,
                "F{$row}" => $c->phone,
                "G{$row}" => $c->email,
                "H{$row}" => $c->address,
                "I{$row}" => $c->npwp,
                "J{$row}" => $saldo != 0.0 ? $saldo : null,
                "N{$row}" => $c->is_active ? 'Ya' : 'Tidak',
            ];
            $row++;
        }

        $row = self::MASTER_FIRST_ROW;
        foreach ($vendors as $v) {
            $saldo = round($openingByVendor[$v->id] ?? 0, 2);
            $jenis = SopLookups::JENIS_VENDOR[$v->type] ?? 'Lainnya';
            $notes = array_filter([
                ! isset(SopLookups::JENIS_VENDOR[$v->type]) && $v->type ? "Jenis MY-TRUCK: {$v->type}" : null,
                $v->email ? "Email: {$v->email}" : null,
            ]);
            $cells['Master Vendor'] += [
                "C{$row}" => (string) $v->code,
                "D{$row}" => $v->name,
                "E{$row}" => $jenis,
                "F{$row}" => $v->contact_person,
                "G{$row}" => $v->phone,
                "H{$row}" => $v->address,
                "I{$row}" => $v->npwp,
                "J{$row}" => $saldo != 0.0 ? $saldo : null,
                "N{$row}" => $v->is_active ? 'Ya' : 'Tidak',
                "O{$row}" => $notes ? implode(' • ', $notes) : null,
            ];
            $row++;
        }

        // ── JURNAL ──────────────────────────────────────────────────────
        $lines = $this->postedLines($cid)
            ->where('je.document_type', '!=', 'saldo_awal')
            ->where('je.entry_date', '>=', $dariStr)
            ->where('je.entry_date', '<', $untilStr)
            ->leftJoin('business_units as bu', 'bu.id', '=', 'je.business_unit_id')
            ->leftJoin('assets as ast', 'ast.id', '=', 'jl.asset_id')
            ->select(
                'je.id as je_id', 'je.entry_number', 'je.entry_date', 'je.document_number',
                'je.description as je_description', 'bu.code as bu_code',
                'jl.account_id', 'jl.description', 'jl.debit', 'jl.kredit', 'ast.asset_code',
            )
            ->orderBy('je.entry_date')
            // Panjang dulu baru nilai = urutan numerik nomor jurnal (J2609-999 < J2609-1000).
            ->orderByRaw('LENGTH(je.entry_number)')
            ->orderBy('je.entry_number')
            ->orderBy('jl.sort_order')
            ->orderBy('jl.id')
            ->get();

        $this->assertCapacity('JURNAL (baris jurnal)', $lines->count(), self::JURNAL_LAST_ROW, self::JURNAL_FIRST_ROW);

        $arusKasByJournal = $this->arusKasByJournal($lines, $cashIds, $accounts);
        $bpMissing        = 0;
        $row              = self::JURNAL_FIRST_ROW;

        foreach ($lines as $l) {
            $type = $types[$l->account_id] ?? null;
            $bp   = null;

            if ($type === 'Piutang' && isset($clientByJournal[$l->je_id])) {
                $c  = $clients[$clientByJournal[$l->je_id]] ?? null;
                $bp = $c ? "{$c->code} | {$c->name}" : null;
            } elseif ($type === 'Hutang Usaha' && isset($vendorByJournal[$l->je_id])) {
                $v  = $vendors[$vendorByJournal[$l->je_id]] ?? null;
                $bp = $v ? "{$v->code} | {$v->name}" : null;
            }
            if (($type === 'Piutang' || $type === 'Hutang Usaha') && $bp === null) {
                $bpMissing++;
            }

            $debit  = round((float) $l->debit, 2);
            $kredit = round((float) $l->kredit, 2);
            $desc   = trim((string) ($l->description ?: $l->je_description));

            $cells['JURNAL'] += [
                "B{$row}" => Carbon::parse($l->entry_date),
                "C{$row}" => $l->document_number ?: $l->entry_number,
                "D{$row}" => $l->entry_number . ($desc !== '' ? " · {$desc}" : ''),
                "E{$row}" => in_array($l->bu_code, SopLookups::LINI, true) ? $l->bu_code : null,
                "F{$row}" => $l->asset_code,
                "G{$row}" => $bp,
                "I{$row}" => $names[$l->account_id] ?? null,
                "K{$row}" => $debit > 0 ? $debit : null,
                "L{$row}" => $kredit > 0 ? $kredit : null,
                "M{$row}" => ($type === 'Kas' || $type === 'Bank') ? ($arusKasByJournal[$l->je_id] ?? 'Operasi') : null,
            ];
            $row++;
        }

        if ($bpMissing > 0) {
            $warnings[] = "{$bpMissing} baris jurnal Piutang/Utang tidak punya klien/vendor di MY-TRUCK "
                . '(mis. jurnal manual) — kolom BUKU PEMBANTU kosong, saldo buku pembantu bisa tidak sama dengan akun.';
        }

        // ── Tagihan & Pembayaran ────────────────────────────────────────
        [$tagihanCells, $pembayaranCells, $invCount, $payCount] = $this->invoicesAndPayments($cid, $dariStr, $untilStr, $clients, $accounts, $names);
        $cells['Tagihan']    = $tagihanCells;
        $cells['Pembayaran'] = $pembayaranCells;

        // ── Master Unit (jembatan) — LR per Unit & dropdown Kode Unit di JURNAL ──
        $units = $this->master->units($cid, $untilStr);
        $this->assertCapacity('Master Unit', $units->count(), SopMasterData::UNIT_LAST_ROW);
        $cells['Master Unit'] = $this->master->unitCells($cid, $untilStr, mirror: true);

        // ── Parameter, periode laporan, info export ─────────────────────
        $cells['Parameter'] = $this->parameterCells($company, $dari, $sampai, $exportedBy,
            'Jurnal sebelum periode & jurnal saldo awal diringkas ke SALDO AWAL di COA. '
            . 'Jurnal void & pembalik tidak disertakan (sama dengan laporan MY-TRUCK).');

        // Periode laporan NRL/LR/NRC/Arus Kas & Dashboard = periode export.
        $cells['NRL']       = ['E3' => $dari->copy(), 'G3' => $sampai->copy()];
        $cells['Dashboard'] = ['I6' => $dari->copy(), 'L6' => $sampai->copy()];

        return [
            'cells'    => $cells,
            'clear'    => $clear,
            'counts'   => [
                'akun'       => $accounts->count(),
                'klien'      => $clients->count(),
                'vendor'     => $vendors->count(),
                'jurnal'     => $lines->count(),
                'tagihan'    => $invCount,
                'pembayaran' => $payCount,
                'unit'       => $units->count(),
            ],
            'warnings' => $warnings,
        ];
    }

    // ────────────────────────────────────────────────────────────────────

    /**
     * Akun yang masuk COA: semua akun postable + header yang pernah dipakai
     * jurnal (akun yang di-split jadi header setelah punya transaksi tetap
     * memegang saldo lama — kalau dibuang, neraca file tidak balance).
     *
     * @return Collection<int, Account>
     */
    private function accountsForCoa(int $cid): Collection
    {
        $accounts = Account::withoutGlobalScopes()->where('company_id', $cid)->orderBy('code')->get();

        $headerCodes = $accounts->pluck('parent_code')->filter()->flip();
        $usedIds     = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $cid)
            ->distinct()
            ->pluck('jl.account_id')
            ->flip();

        return $accounts
            ->filter(fn (Account $a) => ! isset($headerCodes[$a->code]) || isset($usedIds[$a->id]))
            ->values();
    }

    /**
     * Petakan akun MY-TRUCK ke TYPE AKUN SOP (CONTROL!B5:B21).
     * TYPE menentukan saldo normal & kelompok laporan di file, dan
     * Kas/Bank/Piutang/Hutang Usaha memicu aturan khusus di JURNAL.
     */
    private function sopType(Account $a, bool $isCash): string
    {
        if ($isCash) {
            // Kas & Bank sama-sama akun kas di SOP; bedanya hanya label. Nama
            // berawalan "Kas" (termasuk "Kas dan Bank") → Kas.
            $name = trim($a->name);
            if ($a->role === AccountRole::CashPetty || preg_match('/^kas\b/i', $name)) {
                return 'Kas';
            }

            return preg_match('/bank|bca|bri|bni|mandiri|bsi|btn|cimb|rekening/i', $name) ? 'Bank' : 'Kas';
        }

        $role = $a->role instanceof AccountRole ? $a->role : null;

        if ($role) {
            $v = $role->value;
            return match (true) {
                str_starts_with($v, 'receivable_')                  => 'Piutang',
                str_starts_with($v, 'inventory_')                   => 'Persediaan',
                str_starts_with($v, 'akumulasi_')                   => 'Akumulasi Penyusutan',
                str_starts_with($v, 'fixed_asset_'),
                $role === AccountRole::PrepaidFixedAsset            => 'Aset Tetap',
                in_array($role, [AccountRole::PayableVendor, AccountRole::PayableKuari], true) => 'Hutang Usaha',
                in_array($role, [
                    AccountRole::PayableLeasing, AccountRole::PayableBank,
                    AccountRole::PayablePemegangSaham, AccountRole::PayableLainPanjang,
                ], true)                                            => 'Kewajiban Jangka Panjang',
                str_starts_with($v, 'payable_'),
                $role === AccountRole::UangMukaProyek               => 'Kewajiban Lancar Lainnya',
                $role === AccountRole::EquityPrive                  => 'Prive',
                str_starts_with($v, 'equity_'),
                $role === AccountRole::ClosingIkhtisar              => 'Modal',
                $role === AccountRole::RevenueLain                  => 'Pendapatan Lain',
                str_starts_with($v, 'revenue_')                     => 'Pendapatan Usaha',
                str_starts_with($v, 'cogs_')                        => 'Beban Langsung (HPP)',
                in_array($role, [AccountRole::OpexBunga, AccountRole::OpexLain], true) => 'Beban Lain',
                str_starts_with($v, 'opex_')                        => 'Beban Operasional',
                default                                             => $this->sopTypeByCategory($a),
            };
        }

        return $this->sopTypeByCategory($a);
    }

    private function sopTypeByCategory(Account $a): string
    {
        $kredit = $a->normal_balance === 'kredit';

        return match ($a->category) {
            'aset' => $a->sub_category === 'aset_tetap'
                ? ($kredit ? 'Akumulasi Penyusutan' : 'Aset Tetap')
                : 'Aset Lancar Lainnya',
            'kewajiban'  => $a->sub_category === 'kewajiban_panjang' ? 'Kewajiban Jangka Panjang' : 'Kewajiban Lancar Lainnya',
            'ekuitas'    => $kredit ? 'Modal' : 'Prive',
            'pendapatan' => $a->sub_category === 'pendapatan_lain' ? 'Pendapatan Lain' : 'Pendapatan Usaha',
            'beban'      => $a->sub_category === 'beban_hpp' ? 'Beban Langsung (HPP)' : 'Beban Operasional',
            default      => 'Modal', // 'penutup' (ikhtisar laba rugi)
        };
    }

    /**
     * JURNAL mencari kode akun lewat NAMA (MATCH ke COA kolom C, tidak peka
     * huruf besar/kecil, dan memperlakukan * ? ~ sebagai wildcard). Nama
     * ganda atau ber-wildcard membuat baris jurnal jatuh ke akun yang salah.
     *
     * @param  list<string>  $warnings
     * @return array<int, string>  account_id → nama unik
     */
    private function uniqueAccountNames(Collection $accounts, array &$warnings): array
    {
        $base  = $accounts->mapWithKeys(fn (Account $a) => [$a->id => trim($this->safeName($a->name))]);
        $dupes = $base->countBy(fn (string $n) => mb_strtolower($n))->filter(fn (int $c) => $c > 1);

        $names   = [];
        $renamed = 0;
        foreach ($accounts as $a) {
            $name = $base[$a->id];
            if (isset($dupes[mb_strtolower($name)])) {
                $name .= " ({$a->code})";
            }
            if ($name !== trim($a->name)) {
                $renamed++;
            }
            $names[$a->id] = $name;
        }

        if ($renamed > 0) {
            $warnings[] = "{$renamed} nama akun disesuaikan (nama ganda diberi kode, karakter * ? ~ diganti) "
                . 'supaya rumus JURNAL menemukan akun yang tepat.';
        }

        return $names;
    }

    private function safeName(string $name): string
    {
        return strtr($name, ['*' => '-', '?' => '-', '~' => '-']);
    }

    /**
     * @return array{0: array<int,int>, 1: array<int,int>}  [journal_id → client_id, journal_id → vendor_id]
     */
    private function partyByJournal(int $cid): array
    {
        $client = DB::table('invoices')->where('company_id', $cid)->whereNotNull('journal_entry_id')
            ->pluck('client_id', 'journal_entry_id')->all();

        $client += DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('p.company_id', $cid)->whereNotNull('p.journal_entry_id')
            ->pluck('i.client_id', 'p.journal_entry_id')->all();

        $client += DB::table('material_sales')->where('company_id', $cid)->whereNotNull('journal_entry_id')
            ->pluck('client_id', 'journal_entry_id')->all();

        $vendor = DB::table('material_purchases')->where('company_id', $cid)
            ->whereNotNull('journal_entry_id')->whereNotNull('vendor_id')
            ->pluck('vendor_id', 'journal_entry_id')->all();

        $vendor += DB::table('asset_maintenance_logs')->where('company_id', $cid)
            ->whereNotNull('journal_entry_id')->whereNotNull('vendor_id')
            ->pluck('vendor_id', 'journal_entry_id')->all();

        return [$client, $vendor];
    }

    /**
     * Kategori arus kas per jurnal — logika sama dengan CashFlowService:
     * tanpa lawan non-kas = transfer antar kas; selain itu kategori akun
     * lawan pertama yang terisi, default Operasi.
     *
     * @return array<int, string>
     */
    private function arusKasByJournal(Collection $lines, array $cashIds, Collection $accounts): array
    {
        $cfByAccount = $accounts->pluck('cash_flow_category', 'id');
        $result = [];

        foreach ($lines->groupBy('je_id') as $jeId => $jeLines) {
            $lawan = $jeLines->reject(fn ($l) => isset($cashIds[$l->account_id]));

            if ($lawan->isEmpty()) {
                $result[$jeId] = self::ARUS_KAS_TRANSFER;
                continue;
            }

            $cat = $lawan->map(fn ($l) => $cfByAccount[$l->account_id] ?? null)->filter()->first();
            $result[$jeId] = self::ARUS_KAS[$cat] ?? self::ARUS_KAS['operasi'];
        }

        return $result;
    }

    /**
     * Tagihan: invoice terbit dalam periode + invoice lama yang masih
     * bersisa di awal periode (supaya pembayaran dalam periode punya
     * invoice-nya). Draft tidak diekspor (belum jadi piutang).
     *
     * Kolom Dibayar ditimpa angka MY-TRUCK: rumus SOP hanya menjumlah
     * pembayaran yang ada di file, padahal pembayaran sebelum periode
     * tidak ikut diekspor.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: int, 3: int}
     */
    private function invoicesAndPayments(
        int $cid,
        string $dariStr,
        string $untilStr,
        Collection $clients,
        Collection $accounts,
        array $names,
    ): array {
        $codeByAccount = $accounts->pluck('code', 'id');

        $payments = DB::table('payments as p')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'p.journal_entry_id')
            ->where('p.company_id', $cid)
            ->where('p.payment_date', '<', $untilStr)
            ->orderBy('p.payment_date')->orderBy('p.id')
            ->select('p.*', 'je.status as journal_status')
            ->get()
            ->each(fn ($p) => $p->valid = $p->journal_status !== 'void');

        $paymentsByInvoice = $payments->groupBy('invoice_id');

        $invoices = DB::table('invoices as i')
            ->leftJoin('business_units as bu', 'bu.id', '=', 'i.business_unit_id')
            ->where('i.company_id', $cid)
            ->where('i.status', '!=', 'draft')
            ->where('i.invoice_date', '<', $untilStr)
            ->orderBy('i.invoice_date')
            ->orderByRaw('LENGTH(i.invoice_number)')
            ->orderBy('i.invoice_number')
            ->select('i.*', 'bu.code as bu_code')
            ->get()
            ->filter(function ($inv) use ($dariStr, $paymentsByInvoice) {
                if ($inv->invoice_date >= $dariStr) {
                    return true;
                }
                if ($inv->status === 'void') {
                    return false;
                }
                $paidBefore = ($paymentsByInvoice[$inv->id] ?? collect())
                    ->filter(fn ($p) => $p->valid && $p->payment_date < $dariStr)
                    ->sum('amount');

                return (float) $inv->amount - (float) $paidBefore > 0.005;
            })
            ->values();

        $this->assertCapacity('Tagihan (invoice)', $invoices->count(), self::TAGIHAN_LAST_ROW);

        $tagihan = [];
        $row = self::MASTER_FIRST_ROW;
        foreach ($invoices as $inv) {
            $date   = Carbon::parse($inv->invoice_date);
            $termin = $inv->due_date ? max(0, (int) $date->diffInDays(Carbon::parse($inv->due_date))) : null;
            $paid   = ($paymentsByInvoice[$inv->id] ?? collect())->where('valid', true)->sum('amount');
            $client = $clients[$inv->client_id] ?? null;
            $isOld  = $inv->invoice_date < $dariStr;

            $tagihan += [
                "C{$row}"  => $inv->invoice_number,
                "E{$row}"  => $date->day,
                "F{$row}"  => $date->month,
                "G{$row}"  => $date->year,
                // Sheet sumber (Tagih Armada/Rental, DP & Termin) di file Finance hanya
                // IMPORTRANGE ke file lain → pakai 'Manual' supaya klien/lini/nominal
                // terbaca dari kolom J–L. Sumber asli dicatat di Keterangan.
                "H{$row}"  => 'Manual',
                "J{$row}"  => $client ? (string) $client->code : null,
                "K{$row}"  => in_array($inv->bu_code, SopLookups::LINI, true) ? $inv->bu_code : 'UMUM',
                "L{$row}"  => round((float) $inv->amount, 2),
                "M{$row}"  => $inv->description,
                "S{$row}"  => $inv->revenue_account_id && isset($codeByAccount[$inv->revenue_account_id])
                    ? $this->codeValue($codeByAccount[$inv->revenue_account_id])
                    : null,
                "T{$row}"  => $termin,
                "V{$row}"  => round((float) $paid, 2),
                "X{$row}"  => $inv->status === 'void' ? 'Void' : 'Terbit',
                "AD{$row}" => implode(' • ', array_filter([
                    $inv->source_type ? "Sumber MY-TRUCK: {$inv->source_type}" : null,
                    "Status MY-TRUCK: {$inv->status}",
                    $inv->notes,
                ])),
            ];
            if ($tagihan["S{$row}"] === null) {
                unset($tagihan["S{$row}"]); // biarkan rumus default akun pendapatan per lini
            }
            // Status Jurnal: jurnalnya tidak ada di sheet JURNAL bila invoice dari
            // periode sebelumnya / void → rumus akan salah menulis "BELUM".
            if ($inv->status === 'void') {
                $tagihan["AB{$row}"] = 'Void';
            } elseif ($isOld) {
                $tagihan["AB{$row}"] = 'Sudah (periode sebelumnya)';
            }
            $row++;
        }

        $invoiceById = DB::table('invoices')->where('company_id', $cid)->get(['id', 'invoice_number', 'amount'])->keyBy('id');

        $inPeriod = $payments->filter(fn ($p) => $p->payment_date >= $dariStr)->values();
        $this->assertCapacity('Pembayaran', $inPeriod->count(), self::PEMBAYARAN_LAST_ROW);

        $pembayaran = [];
        $row = self::MASTER_FIRST_ROW;
        foreach ($inPeriod as $p) {
            $date = Carbon::parse($p->payment_date);
            $inv  = $invoiceById[$p->invoice_id] ?? null;

            // Sisa sebelum pembayaran ini = nominal invoice − pembayaran sah sebelumnya
            // (urut tanggal lalu id, sama dengan urutan $payments).
            $paidBefore = 0.0;
            foreach ($paymentsByInvoice[$p->invoice_id] ?? [] as $other) {
                if ($other->id === $p->id) {
                    break;
                }
                if ($other->valid) {
                    $paidBefore += (float) $other->amount;
                }
            }

            $pembayaran += [
                "C{$row}" => $p->payment_number,
                "E{$row}" => $date->day,
                "F{$row}" => $date->month,
                "G{$row}" => $date->year,
                "H{$row}" => $inv?->invoice_number,
                "M{$row}" => $inv ? round((float) $inv->amount - $paidBefore, 2) : null,
                "N{$row}" => round((float) $p->amount, 2),
                "O{$row}" => $names[$p->cash_account_id] ?? null,
                "P{$row}" => $p->reference_number,
                "R{$row}" => $p->valid ? 'Valid' : 'Batal',
                "U{$row}" => $p->description,
            ];
            $row++;
        }

        return [$tagihan, $pembayaran, $invoices->count(), $inPeriod->count()];
    }
}
