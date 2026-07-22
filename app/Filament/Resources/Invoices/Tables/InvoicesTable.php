<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Account;
use App\Models\Invoice;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PaymentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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

                TextColumn::make('umur_hari')
                    ->label('Umur')
                    ->getStateUsing(fn ($record) => $record->umur_hari . ' hari')
                    ->badge()
                    ->color(fn ($record) => match ($record->aging_category) {
                        'overdue'   => 'danger',
                        'perhatian' => 'warning',
                        default     => 'success',
                    })
                    ->visible(fn ($record) => $record?->canReceivePayment() ?? true),

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

                TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
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

                SelectFilter::make('aging')
                    ->label('Aging')
                    ->options([
                        'lancar'    => 'Lancar (<14 hari)',
                        'perhatian' => 'Perhatian (14-30 hari)',
                        'overdue'   => 'Overdue (>30 hari)',
                    ])
                    ->query(function ($query, array $data) {
                        if (! ($data['value'] ?? null)) {
                            return $query;
                        }
                        return $query->whereIn('status', ['terbit', 'sebagian'])
                            ->where(function ($q) use ($data) {
                                $today = now()->toDateString();
                                if ($data['value'] === 'overdue') {
                                    $q->whereRaw('DATEDIFF(?, invoice_date) > 30', [$today]);
                                } elseif ($data['value'] === 'perhatian') {
                                    $q->whereRaw('DATEDIFF(?, invoice_date) BETWEEN 15 AND 30', [$today]);
                                } else {
                                    $q->whereRaw('DATEDIFF(?, invoice_date) <= 14', [$today]);
                                }
                            });
                    }),
            ])
            ->recordActions([
                // Issue: draft → terbit, auto-post jurnal
                Action::make('issue')
                    ->label('Terbitkan')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
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
                            ->options(function (Invoice $record) {
                                return Account::query()
                                    ->where('company_id', $record->company_id)
                                    ->where('is_active', true)
                                    ->where('sub_category', 'aset_lancar')
                                    ->where('code', 'like', '111%')
                                    ->postable()
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText('Pilih sub-akun spesifik (BCA / Mandiri / dll). Akun header tidak muncul.'),

                        TextInput::make('amount')
                            ->label('Nominal (Rp)')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(1)
                            ->default(fn (Invoice $record) => (float) $record->sisa)
                            ->helperText(fn (Invoice $record) => 'Sisa piutang: Rp ' . number_format($record->sisa, 0, ',', '.')),

                        TextInput::make('reference_number')
                            ->label('No. Bukti / Transfer')
                            ->maxLength(100),

                        Textarea::make('description')
                            ->label('Catatan')
                            ->rows(2),
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
                    ->visible(fn (Invoice $r): bool => in_array($r->status, ['terbit']) && abs((float) $r->paid_amount) < 0.005)
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
}
