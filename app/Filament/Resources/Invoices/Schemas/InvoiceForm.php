<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\AccountRole;
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
                    ->description('Sistem butuh 2 akun ini untuk membuat jurnal saat invoice diterbitkan. Kalau ada tanda ❌, perbaiki dulu sebelum Terbitkan.')
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
            return new HtmlString('<div class="text-sm text-gray-500">Tenant belum tersedia.</div>');
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
            : '<div class="text-sm text-gray-500 italic px-1">Pilih Lini Bisnis dulu untuk cek akun pendapatan.</div>';

        return new HtmlString(
            '<div class="space-y-2">' . $receivableRow . $revenueRow . '</div>'
        );
    }

    /**
     * Format 1 baris status akun. Return HTML string (bukan HtmlString).
     */
    protected static function coaRow(string $label, ?Account $account, string $fallbackCode, AccountRole $fallbackRole): string
    {
        if (! $account) {
            return sprintf(
                '<div class="flex items-start gap-2 p-2 rounded border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800">'
                . '<span class="text-red-600 dark:text-red-400 font-bold">❌</span>'
                . '<div class="text-sm">'
                . '<div class="font-medium text-red-900 dark:text-red-100">%s — akun belum ada</div>'
                . '<div class="text-red-700 dark:text-red-300 text-xs mt-1">'
                . 'Sistem mencari akun ber-role <code class="px-1 rounded bg-red-100 dark:bg-red-800">%s</code> ATAU kode <code class="px-1 rounded bg-red-100 dark:bg-red-800">%s</code>. '
                . 'Buka <strong>Master Data → Daftar Akun</strong>, buat akun baru atau set role di akun existing.'
                . '</div>'
                . '</div>'
                . '</div>',
                e($label),
                e($fallbackRole->value),
                e($fallbackCode),
            );
        }

        if (! $account->isPostable()) {
            return sprintf(
                '<div class="flex items-start gap-2 p-2 rounded border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800">'
                . '<span class="text-amber-600 dark:text-amber-400 font-bold">⚠️</span>'
                . '<div class="text-sm">'
                . '<div class="font-medium text-amber-900 dark:text-amber-100">%s — [%s] %s (HEADER)</div>'
                . '<div class="text-amber-700 dark:text-amber-300 text-xs mt-1">'
                . 'Akun ini header — punya sub-akun. Sistem otomatis pakai first child postable. '
                . 'Kalau tidak ada child aktif, buat dulu di Daftar Akun.'
                . '</div>'
                . '</div>'
                . '</div>',
                e($label),
                e($account->code),
                e($account->name),
            );
        }

        return sprintf(
            '<div class="flex items-start gap-2 p-2 rounded border border-emerald-200 bg-emerald-50 dark:bg-emerald-900/20 dark:border-emerald-800">'
            . '<span class="text-emerald-600 dark:text-emerald-400 font-bold">✅</span>'
            . '<div class="text-sm">'
            . '<div class="font-medium text-emerald-900 dark:text-emerald-100">%s</div>'
            . '<div class="text-emerald-700 dark:text-emerald-300 text-xs">Akan pakai: <code class="px-1 rounded bg-emerald-100 dark:bg-emerald-800">[%s] %s</code></div>'
            . '</div>'
            . '</div>',
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
