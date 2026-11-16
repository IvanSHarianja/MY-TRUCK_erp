<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Models\Client;
use App\Models\Payment;
use App\Support\PaymentTiming;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat pembayaran satu klien di halaman detail klien. Hanya-baca:
 * pembayaran diinput & dibatalkan dari menu Tagihan & Piutang.
 *
 * Widget tabel dengan query Payment biasa (bukan RelationManager atas
 * relasi hasManyThrough) supaya bebas dari keterbatasan relasi tsb.
 */
class ClientPaymentHistory extends TableWidget
{
    public ?Model $record = null;

    protected static ?string $heading = 'Riwayat Pembayaran';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $clientId = $this->record instanceof Client ? $this->record->getKey() : 0;
        // Subquery berkorelasi (bukan JOIN): payments & invoices sama-sama punya
        // kolom `amount`, JOIN membuat sort/search kolom itu ambigu.
        $due = 'COALESCE(invoices.due_date, invoices.invoice_date)';

        return $table
            ->query(fn () => Payment::query()
                ->whereHas('invoice', fn (Builder $q) => $q->where('client_id', $clientId))
                ->with(['invoice', 'cashAccount', 'journalEntry']))
            ->defaultSort('payment_date', 'desc')
            ->emptyStateHeading('Belum ada pembayaran dari klien ini')
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Tanggal Bayar')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('payment_number')
                    ->label('No. Pembayaran')
                    ->searchable(),

                TextColumn::make('invoice.invoice_number')
                    ->label('No. Invoice')
                    ->searchable(),

                TextColumn::make('invoice.due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y'),

                TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('ketepatan')
                    ->label('Ketepatan')
                    ->badge()
                    ->getStateUsing(fn (Payment $r) => PaymentTiming::paymentLabel(self::daysLate($r)))
                    ->color(fn (Payment $r) => self::daysLate($r) > 0 ? 'warning' : 'success'),

                TextColumn::make('cashAccount.name')
                    ->label('Diterima ke')
                    ->toggleable(),

                TextColumn::make('reference_number')
                    ->label('No. Bukti')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status_bayar')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (Payment $r) => $r->journalEntry?->isVoid() ? 'Batal' : 'Sah')
                    ->color(fn (string $state) => $state === 'Batal' ? 'danger' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('ketepatan')
                    ->label('Ketepatan')
                    ->options(['tepat' => 'Tepat waktu', 'telat' => 'Telat'])
                    // Nama parameter HARUS $query & $data: Filament menyuntikkan argumen
                    // closure berdasarkan nama — nama lain (mis. $q) dapat Builder kosong
                    // tanpa model dari container → crash.
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'telat' => $query->whereHas('invoice', fn (Builder $i) => $i->whereRaw("payments.payment_date > {$due}")),
                        'tepat' => $query->whereHas('invoice', fn (Builder $i) => $i->whereRaw("payments.payment_date <= {$due}")),
                        default => $query,
                    }),
            ]);
    }

    private static function daysLate(Payment $payment): int
    {
        return PaymentTiming::daysLate($payment->invoice?->effectiveDueDate(), $payment->payment_date);
    }
}
