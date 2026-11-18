<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PaymentService;
use App\Support\PaymentTiming;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('invoice_date', 'desc')
            // Tanggal pelunasan untuk kolom Keterlambatan — sekali query, bukan per baris.
            ->modifyQueryUsing(fn ($query) => $query->withMax('payments', 'payment_date'))
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('No. Invoice')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('invoice_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('businessUnit.code')
                    ->label('Lini')
                    ->badge(),

                TextColumn::make('description')
                    ->label('Uraian')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->description)
                    ->toggleable(),

                TextColumn::make('amount')
                    ->label('Nilai')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('paid_amount')
                    ->label('Dibayar')
                    ->money('IDR')
                    ->toggleable(),

                TextColumn::make('sisa')
                    ->label('Sisa')
                    ->money('IDR')
                    ->getStateUsing(fn ($record) => $record->sisa),

                TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->sortable(),

                // Telat bayar dihitung dari JATUH TEMPO (PaymentTiming).
                TextColumn::make('keterlambatan')
                    ->label('Keterlambatan')
                    ->getStateUsing(fn (Invoice $record) => $record->timelinessLabel())
                    ->badge()
                    ->color(fn (Invoice $record) => $record->timelinessColor()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft'    => 'gray',
                        'terbit'   => 'info',
                        'sebagian' => 'warning',
                        'lunas'    => 'success',
                        'void'     => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft'    => 'Draft',
                        'terbit'   => 'Terbit',
                        'sebagian' => 'Sebagian Dibayar',
                        'lunas'    => 'Lunas',
                        'void'     => 'Void',
                    ]),

                SelectFilter::make('business_unit_id')
                    ->label('Lini Bisnis')
                    ->relationship('businessUnit', 'name'),

                // Dihitung dari JATUH TEMPO. Batas tanggal dihitung di PHP supaya
                // query portabel (dulu DATEDIFF = khusus MySQL).
                SelectFilter::make('keterlambatan')
                    ->label('Keterlambatan')
                    ->options(PaymentTiming::BUCKETS + ['lunas_telat' => 'Lunas tapi telat'])
                    ->query(fn ($query, array $data) => self::applyLatenessFilter($query, $data['value'] ?? null)),
            ])
            ->recordActions([
                // Issue: draft → terbit, auto-post jurnal
                Action::make('issue')
                    ->label('Terbitkan')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->authorize(fn (): bool => auth()->user()?->canCurrent(Permission::InvoiceCreate) ?? false)
                    ->visible(fn (Invoice $r): bool => $r->isDraft())
                    ->requiresConfirmation()
                    ->modalHeading('Terbitkan Invoice')
                    ->modalDescription('Invoice akan diterbitkan dan jurnal otomatis di-post. No invoice akan di-generate.')
                    ->action(function (Invoice $record) {
                        try {
                            app(InvoiceService::class)->issue($record);
                            Notification::make()->title('Invoice diterbitkan & jurnal otomatis di-post')->success()->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal terbitkan invoice')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()->send();
                        }
                    }),

                // Terima Pembayaran
                Action::make('pay')
                    ->label('Terima Pembayaran')
                    ->icon(Heroicon::Banknotes)
                    ->color('info')
                    ->authorize(fn (): bool => auth()->user()?->canCurrent(Permission::PaymentManage) ?? false)
                    ->visible(fn (Invoice $r): bool => $r->canReceivePayment())
                    ->schema([
                        DatePicker::make('payment_date')
                            ->label('Tanggal Bayar')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Select::make('cash_account_id')
                            ->label('Diterima ke Akun')
                            ->required()
                            ->options(fn (Invoice $record) => Account::cashAccounts($record->company_id)
                                ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])
                                ->toArray())
                            ->searchable()
                            ->helperText(fn (Invoice $record): string => Account::cashAccounts($record->company_id)->isEmpty()
                                ? '⚠️ Belum ada sub-akun kas/bank. Buka Master Data → Daftar Akun. Buat akun parent kas (mis. [111100] Kas dan Bank) lalu tambah sub-akun spesifik di bawahnya (mis. [111100-01] Kas BCA, [111100-02] Kas Mandiri) — parent-nya ikat ke akun kas utama. Sub-akun tersebutlah yang akan muncul di dropdown ini.'
                                : 'Pilih sub-akun spesifik (BCA / Mandiri / dll). Akun HEADER dan akun tanpa parent tidak muncul.'),

                        TextInput::make('amount')
                            ->label('Nominal (Rp)')
                            ->required()
                            ->rupiah()
                            ->default(fn (Invoice $record) => (float) $record->sisa)
                            ->helperText(fn (Invoice $record) => 'Sisa piutang: Rp ' . number_format($record->sisa, 0, ',', '.')),

                        TextInput::make('reference_number')
                            ->label('No. Bukti / Transfer')
                            ->maxLength(100),

                        Textarea::make('description')
                            ->label('Catatan')
                            ->rows(2),

                        FileUpload::make('bukti_tf_path')->buktiTf(),
                    ])
                    ->action(function (array $data, Invoice $record) {
                        try {
                            $cashAccount = Account::findOrFail($data['cash_account_id']);
                            app(PaymentService::class)->pay(
                                invoice: $record,
                                cashAccount: $cashAccount,
                                amount: (float) $data['amount'],
                                paymentDate: \Carbon\Carbon::parse($data['payment_date']),
                                referenceNumber: $data['reference_number'] ?? null,
                                description: $data['description'] ?? null,
                                buktiTfPath: $data['bukti_tf_path'] ?? null,
                            );
                            Notification::make()->title('Pembayaran diterima & jurnal di-post otomatis')->success()->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal terima pembayaran')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()->send();
                        }
                    }),

                // Cetak PDF (untuk invoice yang sudah terbit)
                Action::make('print_pdf')
                    ->label('Cetak PDF')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->visible(fn (Invoice $r): bool => in_array($r->status, ['terbit', 'sebagian', 'lunas']))
                    ->url(fn (Invoice $r) => route('pdf.invoice', ['invoice' => $r->id]))
                    ->openUrlInNewTab(),

                // Edit (hanya draft)
                EditAction::make()
                    ->visible(fn (Invoice $r): bool => $r->isDraft()),

                // Void
                Action::make('void')
                    ->label('Void')
                    ->icon(Heroicon::XCircle)
                    ->color('danger')
                    // BUG-08: pakai epsilon tolerance untuk hindari float drift
                    // (mis. 1e-10 residu dari sum) yang bikin button hilang padahal
                    // invoice belum ada pembayaran nyata.
                    ->visible(fn (Invoice $r): bool => in_array($r->status, ['terbit'])
                        && abs((float) $r->paid_amount) < 0.005
                        && (auth()->user()?->can('void', $r) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Void Invoice')
                    ->modalDescription('Invoice akan di-void dan jurnal pembalik akan di-post otomatis. Aksi ini tidak bisa dibatalkan.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan Void')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (array $data, Invoice $record) {
                        try {
                            app(InvoiceService::class)->void($record, $data['reason']);
                            Notification::make()->title('Invoice di-void & jurnal pembalik di-post')->success()->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Gagal void invoice')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()->send();
                        }
                    }),
            ]);
    }

    /**
     * Filter keterlambatan. Telat N hari = jatuh tempo < hari ini − N, jadi
     * rentang "telat a–b hari" = jatuh tempo di [hari ini − b, hari ini − a].
     */
    public static function applyLatenessFilter(Builder $query, ?string $value, ?Carbon $today = null): Builder
    {
        if (! $value) {
            return $query;
        }

        $today ??= Carbon::today();
        $due   = 'COALESCE(invoices.due_date, invoices.invoice_date)';
        $d     = fn (int $days) => $today->copy()->subDays($days)->toDateString();

        if ($value === 'lunas_telat') {
            return $query->where('status', 'lunas')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('payments')
                    ->whereColumn('payments.invoice_id', 'invoices.id')
                    ->whereRaw("payments.payment_date > {$due}"));
        }

        $query->whereIn('status', ['terbit', 'sebagian']);

        return match ($value) {
            'belum' => $query->whereRaw("{$due} >= ?", [$d(0)]),
            '1-30'  => $query->whereRaw("{$due} < ?", [$d(0)])->whereRaw("{$due} >= ?", [$d(30)]),
            '31-60' => $query->whereRaw("{$due} < ?", [$d(30)])->whereRaw("{$due} >= ?", [$d(60)]),
            '61-90' => $query->whereRaw("{$due} < ?", [$d(60)])->whereRaw("{$due} >= ?", [$d(90)]),
            '>90'   => $query->whereRaw("{$due} < ?", [$d(90)]),
            default => $query,
        };
    }
}
