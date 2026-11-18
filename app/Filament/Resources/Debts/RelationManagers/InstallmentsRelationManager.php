<?php

namespace App\Filament\Resources\Debts\RelationManagers;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\DebtInstallment;
use App\Services\Accounting\DebtService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/** Jadwal cicilan + bayar / batal bayar (jurnal otomatis). */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Jadwal Cicilan';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $canManage = fn (): bool => ($t = Filament::getTenant()) && auth()->user()?->canIn($t, Permission::DebtManage);
        $service = fn () => app(DebtService::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['journalEntry', 'cashAccount']))
            ->defaultSort('ke')
            ->paginated([12, 24, 60, 'all'])
            ->columns([
                TextColumn::make('ke')->label('Ke'),
                TextColumn::make('due_date')->label('Jatuh Tempo')->date('d M Y'),
                TextColumn::make('urgency')->label('Keterangan')->badge()
                    ->state(fn (DebtInstallment $r) => $r->dueLabel())
                    ->color(fn (DebtInstallment $r) => match ($r->urgency()) {
                        'paid' => 'success', 'overdue' => 'danger', 'soon' => 'warning', default => 'gray',
                    }),
                TextColumn::make('pokok')->label('Pokok')->money('IDR', 0)->alignEnd(),
                TextColumn::make('bunga')->label('Bunga')->money('IDR', 0)->alignEnd(),
                TextColumn::make('total')->label('Total')->alignEnd()->weight('bold')
                    ->state(fn (DebtInstallment $r) => $r->isPaid() ? $r->paidTotal() : $r->total())
                    ->money('IDR', 0)
                    ->description(fn (DebtInstallment $r) => $r->denda ? 'termasuk denda Rp ' . number_format($r->denda, 0, ',', '.') : null),
                TextColumn::make('cashAccount.name')->label('Dibayar dari')->placeholder('—')->toggleable(),
                TextColumn::make('journalEntry.entry_number')->label('Jurnal')->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('pay')
                    ->label('Bayar')->icon(Heroicon::OutlinedBanknotes)->color('success')
                    ->authorize($canManage)
                    ->visible(fn (DebtInstallment $r) => ! $r->isPaid())
                    ->modalHeading(fn (DebtInstallment $r) => "Bayar cicilan ke-{$r->ke}")
                    ->modalDescription(fn (DebtInstallment $r) => 'Pokok Rp ' . number_format($r->pokok, 0, ',', '.')
                        . ' · jatuh tempo ' . $r->due_date->format('d M Y') . ' (' . $r->dueLabel() . ')')
                    ->fillForm(fn (DebtInstallment $r) => ['paid_date' => now()->toDateString(), 'bunga' => $r->bunga, 'denda' => 0])
                    ->schema([
                        DatePicker::make('paid_date')->label('Tanggal Bayar')->required()->native(false),
                        Select::make('cash_account_id')->label('Dibayar dari Rekening')->required()->native(false)
                            ->options(fn () => Account::cashAccounts(Filament::getTenant()->getKey())
                                ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])->all()),
                        TextInput::make('bunga')->label('Bunga')->rupiah()
                            ->helperText('Sesuaikan bila bunga di tagihan bank berbeda.'),
                        TextInput::make('denda')->label('Denda Keterlambatan')->rupiah(),
                    ])
                    ->action(function (DebtInstallment $r, array $data) use ($service) {
                        $money = app(\App\Services\Import\AssetImportService::class);
                        $data['bunga'] = $money->money($data['bunga'] ?? 0) ?? 0;
                        $data['denda'] = $money->money($data['denda'] ?? 0) ?? 0;
                        try {
                            $service()->pay($r, auth()->user(), $data);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Gagal membayar')
                                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();

                            return;
                        }
                        Notification::make()->success()->title("Cicilan ke-{$r->ke} lunas & dijurnal")->send();
                    }),

                Action::make('cancelPayment')
                    ->label('Batal Bayar')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->authorize($canManage)
                    ->visible(fn (DebtInstallment $r) => $r->isPaid())
                    ->requiresConfirmation()
                    ->modalDescription('Jurnal pembayaran di-void dan cicilan kembali belum dibayar.')
                    ->action(function (DebtInstallment $r) use ($service) {
                        try {
                            $service()->cancelPayment($r, auth()->user());
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Gagal')
                                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                        }
                    }),
            ]);
    }
}
