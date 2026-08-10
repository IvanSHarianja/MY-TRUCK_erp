<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\AccountRole;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Invoice')
                    ->columns(2)
                    ->schema([
                        TextInput::make('invoice_number')
                            ->label('No. Invoice')
                            ->placeholder('Otomatis saat Issue')
                            ->disabled()
                            ->dehydrated(false),

                        DatePicker::make('invoice_date')
                            ->label('Tanggal Invoice')
                            ->required()
                            ->default(now())
                            ->native(false),

                        DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->default(fn() => now()->addDays(30))
                            ->native(false),

                        Select::make('client_id')
                            ->label('Pelanggan')
                            ->relationship('client', 'name', fn($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('business_unit_id')
                            ->label('Lini Bisnis')
                            ->relationship('businessUnit', 'name')
                            ->getOptionLabelFromRecordUsing(fn($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required()
                            ->helperText('Akun pendapatan otomatis di-set sesuai lini bisnis'),

                        Select::make('revenue_account_id')
                            ->label('Akun Pendapatan (override)')
                            ->options(function () {
                                $tenant = Filament::getTenant();
                                $query = Account::query()
                                    ->where('is_active', true)
                                    ->where('category', 'pendapatan')
                                    ->postable();  // ← hanya leaf
                    
                                if ($tenant) {
                                    $query->where('company_id', $tenant->getKey());
                                }

                                return $query
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn($a) => [$a->id => "[{$a->code}] {$a->name}"])
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText('Kosongkan untuk pakai default sesuai lini bisnis. Akun HEADER otomatis disembunyikan.'),
                    ]),

                Section::make('Detail Penagihan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('amount')
                            ->label('Nominal (Rp)')
                            ->required()
                            ->default(0)
                            ->rupiah(),

                        Textarea::make('description')
                            ->label('Keterangan / Uraian Penagihan')
                            ->rows(2)
                            ->placeholder('contoh: Sewa EX-01 — 28 jam @ Rp 350.000')
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Catatan Internal')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                // Pre-flight check: kasih user tahu akun apa yang akan dipakai
                // sistem saat "Terbitkan Invoice" dan apakah akun itu tersedia.
                // Muncul dinamis mengikuti pilihan Lini Bisnis (live field).
                Section::make('Cek Kesiapan Akun COA')
                    ->description('Sistem butuh 2 akun ini untuk membuat jurnal saat invoice diterbitkan. Kalau ada tanda merah, buat/lengkapi akunnya dulu sebelum Terbitkan.')
                    ->collapsible()
                    ->schema([
                        Placeholder::make('coa_readiness')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Get $get): HtmlString => static::renderCoaReadiness(
                                (int) (Filament::getTenant()?->id ?? 0),
                                $get('business_unit_id') !== null ? (int) $get('business_unit_id') : null,
                            )),
                    ]),

                // Section muncul hanya kalau ada payment dengan bukti. Klik thumbnail
                // buka gambar ukuran penuh di tab baru. Multi payment (partial) di-render
                // sebagai grid.
                Section::make('Bukti Pembayaran')
                    ->visible(fn (?Invoice $record): bool => (bool) $record
                        && $record->payments()->whereNotNull('bukti_tf_path')->exists())
                    ->schema([
                        Placeholder::make('bukti_tf_gallery')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Invoice $record): HtmlString => static::renderBuktiTfGallery($record)),
                    ]),
            ]);
    }

    /**
     * Render 2 baris status akun yang wajib ada untuk terbitkan invoice:
     * - Piutang Usaha (universal, semua lini bisnis pakai)
     * - Pendapatan (per lini bisnis yang dipilih)
     *
     * Resolusi sama persis dengan InvoiceService::resolveRevenueAccount /
     * resolveReceivableAccount (role first, fallback code). Kalau service
     * kelak ganti resolusi, method ini harus ikut disinkron.
     */
    public static function renderCoaReadiness(int $companyId, ?int $businessUnitId): HtmlString
    {
        if ($companyId === 0) {
            return new HtmlString('<div style="padding: 12px; font-size: 13px; color: #6b7280;">Tenant belum tersedia.</div>');
        }

        // === Piutang Usaha (universal) ===
        $receivableRow = static::coaRow(
            label: 'Piutang Usaha',
            account: Account::findByRoleOrCode(AccountRole::ReceivableUsaha, '111200', $companyId),
            fallbackCode: '111200',
            fallbackRole: AccountRole::ReceivableUsaha,
        );

        // === Pendapatan sesuai lini bisnis ===
        $buCode = null;
        if ($businessUnitId) {
            $buCode = BusinessUnit::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', $businessUnitId)
                ->value('code');
        }

        [$revenueRole, $revenueCode, $revenueLabel] = match ($buCode) {
            'RENT'  => [AccountRole::RevenueRent, '441100', 'Pendapatan Sewa Alat (RENT)'],
            'ARMD'  => [AccountRole::RevenueArmd, '441200', 'Pendapatan Ritase Dump Truck (ARMD)'],
            'MATL'  => [AccountRole::RevenueMatl, '441300', 'Pendapatan Penjualan Material (MATL)'],
            'BONG'  => [AccountRole::RevenueBong, '441400', 'Pendapatan Borongan Pengurugan (BONG)'],
            null    => [null, null, null],
            default => [AccountRole::RevenueLain, '441900', 'Pendapatan Lain-lain'],
        };

        $revenueRow = $revenueRole
            ? static::coaRow(
                label: $revenueLabel,
                account: Account::findByRoleOrCode($revenueRole, $revenueCode, $companyId),
                fallbackCode: $revenueCode,
                fallbackRole: $revenueRole,
            )
            : '<div style="padding: 12px 14px; border-radius: 8px; background: rgba(148, 163, 184, 0.10); font-size: 13px; color: #64748b; font-style: italic;">Pilih <strong>Lini Bisnis</strong> di atas untuk cek akun pendapatan yang dibutuhkan.</div>';

        return new HtmlString(
            '<div style="display: flex; flex-direction: column; gap: 8px;">' . $receivableRow . $revenueRow . '</div>'
        );
    }

    /**
     * Format 1 baris status akun. Return HTML string (bukan HtmlString).
     *
     * 3 status:
     *   - Missing  (merah) : akun belum ada + CTA link ke create Account
     *   - Header   (kuning): akun ada tapi header, akan fallback ke child
     *   - Ready    (hijau) : akun postable siap dipakai
     *
     * Pakai inline style (bukan Tailwind class) karena HTML fragment ini
     * di-render lewat HtmlString di dalam Placeholder — Tailwind JIT tidak
     * scan file .php ini untuk compile class.
     */
    protected static function coaRow(string $label, ?Account $account, string $fallbackCode, AccountRole $fallbackRole): string
    {
        // === MISSING ===
        if (! $account) {
            $createUrl = '';
            try {
                $createUrl = AccountResource::getUrl('create');
            } catch (\Throwable) {
                $createUrl = AccountResource::getUrl('index');
            }

            // Nama saran = label akun tanpa suffix "(RENT/ARMD/...)" — supaya
            // string yang di-ketik user natural. Sistem AccountForm punya
            // auto-suggest role dari nama, jadi user cukup ketik ini →
            // role otomatis ter-set ke $fallbackRole.
            $suggestedName = preg_replace('/\s*\([A-Z]+\)$/', '', $label);

            return sprintf(
                '<div style="display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: 10px; border: 1px solid rgba(220, 38, 38, 0.35); background: linear-gradient(90deg, rgba(254, 226, 226, 0.6), rgba(254, 226, 226, 0.3));">
                    <div style="flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%%; background: #dc2626; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; line-height: 1;">✕</div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; color: #991b1b; font-size: 14px;">%s <span style="font-weight: 400; opacity: 0.85;">— akun belum ada</span></div>

                        <div style="margin-top: 10px; padding: 10px 12px; background: rgba(255, 255, 255, 0.5); border-radius: 6px; border: 1px dashed rgba(220, 38, 38, 0.3); font-size: 12px; color: #7f1d1d; line-height: 1.6;">
                            <div style="font-weight: 600; margin-bottom: 4px;">💡 Cara mudah buat akun ini:</div>
                            <ol style="margin: 0 0 0 20px; padding: 0;">
                                <li>Klik <strong>+ Buat Akun Sekarang</strong> di bawah</li>
                                <li>Isi kolom <strong>Kode Akun</strong>: <code style="padding: 1px 6px; background: rgba(220, 38, 38, 0.15); border-radius: 3px; font-family: SF Mono, Consolas, monospace; font-size: 11px;">%s</code></li>
                                <li>Isi kolom <strong>Nama</strong>: <code style="padding: 1px 6px; background: rgba(220, 38, 38, 0.15); border-radius: 3px; font-family: SF Mono, Consolas, monospace; font-size: 11px;">%s</code>
                                    <span style="opacity: 0.75;">(role akan otomatis ter-set jadi <strong>%s</strong>)</span></li>
                                <li>Simpan → invoice bisa diterbitkan</li>
                            </ol>
                        </div>

                        <div style="margin-top: 10px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <a href="%s" target="_blank" rel="noopener"
                               style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; background: #dc2626; color: white; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.1); transition: background 0.15s;"
                               onmouseover="this.style.background=\'#b91c1c\'"
                               onmouseout="this.style.background=\'#dc2626\'">
                                + Buat Akun Sekarang
                            </a>
                            <span style="font-size: 11px; color: #991b1b; opacity: 0.7;">
                                Alternatif: set role <code style="padding: 1px 5px; background: rgba(220, 38, 38, 0.1); border-radius: 3px; font-family: SF Mono, Consolas, monospace; font-size: 10px;">%s</code> di akun yang sudah ada
                            </span>
                        </div>
                    </div>
                </div>',
                e($label),
                e($fallbackCode),
                e($suggestedName),
                e($fallbackRole->label()),
                e($createUrl),
                e($fallbackRole->value),
            );
        }

        // === HEADER (parent) ===
        if (! $account->isPostable()) {
            return sprintf(
                '<div style="display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: 10px; border: 1px solid rgba(245, 158, 11, 0.35); background: linear-gradient(90deg, rgba(254, 243, 199, 0.6), rgba(254, 243, 199, 0.3));">
                    <div style="flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%%; background: #d97706; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; line-height: 1;">!</div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; color: #78350f; font-size: 14px;">%s <span style="font-weight: 400; opacity: 0.85;">— [%s] %s (HEADER)</span></div>
                        <div style="font-size: 12px; color: #78350f; margin-top: 6px; line-height: 1.55;">
                            Akun ini <strong>header</strong> (punya sub-akun). Sistem otomatis pakai <em>first child postable</em>.
                            Kalau belum ada child aktif, buat dulu di <strong>Master Data → Daftar Akun</strong>.
                        </div>
                    </div>
                </div>',
                e($label),
                e($account->code),
                e($account->name),
            );
        }

        // === READY (postable, siap pakai) ===
        return sprintf(
            '<div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.35); background: linear-gradient(90deg, rgba(209, 250, 229, 0.5), rgba(209, 250, 229, 0.2));">
                <div style="flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%%; background: #10b981; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; line-height: 1;">✓</div>
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 600; color: #065f46; font-size: 14px;">%s</div>
                    <div style="font-size: 12px; color: #047857; margin-top: 2px;">
                        Akan pakai: <code style="padding: 1px 6px; background: rgba(16, 185, 129, 0.15); border-radius: 3px; font-family: SF Mono, Consolas, monospace; font-size: 11px; color: #065f46;">[%s] %s</code>
                    </div>
                </div>
            </div>',
            e($label),
            e($account->code),
            e($account->name),
        );
    }

    public static function renderBuktiTfGallery(Invoice $invoice): HtmlString
    {
        $payments = $invoice->payments()
            ->whereNotNull('bukti_tf_path')
            ->orderBy('payment_date')
            ->get();

        $html = '<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">';
        foreach ($payments as $p) {
            $url = e($p->bukti_tf_url);
            $html .= '<a href="' . $url . '" target="_blank" rel="noopener" '
                . 'class="block rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition">'
                . '<img src="' . $url . '" loading="lazy" alt="Bukti Transfer" '
                . 'class="w-full h-40 object-cover bg-gray-50 dark:bg-gray-800">'
                . '</a>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }
}
