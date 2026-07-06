<?php

namespace App\Filament\Pages;

use App\Enums\QuickTransactionType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BusinessUnit;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\QuickTransactionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Halaman "Transaksi & Beban Terpadu".
 *
 * UX: User pilih jenis transaksi (12 tipe) → form dinamis menyesuaikan metode &
 * akun lawan → submit → otomatis posted JournalEntry dengan document_type='quick_tx'.
 *
 * Tabel di bawah menampilkan riwayat quick_tx dengan filter periode & status,
 * action Void (call JournalService::void() → auto-reversing).
 *
 * Akses: hanya role owner/admin/accountant (viewer tidak boleh post jurnal).
 */
class QuickTransaction extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $view = 'filament.pages.quick-transaction';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Transaksi & Beban';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Transaksi & Beban Terpadu';

    public ?array $data = [];

    /**
     * Authorization: hanya role owner/admin/accountant yang boleh akses
     * (viewer tidak boleh post jurnal).
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return false;
        }
        $user = auth()->user();
        $pivot = $user?->companies()
            ->where('companies.id', $tenant->getKey())
            ->first()?->pivot;

        return $pivot && in_array($pivot->role, ['owner', 'admin', 'accountant'], true);
    }

    public function mount(): void
    {
        // Hanya isi default untuk field yang 99% benar (tanggal = hari ini).
        // type/method/business_unit_id sengaja dibiarkan kosong supaya user
        // sadar memilih — mencegah submit asal dan menghindari konflik dengan
        // validasi HPP (BebanSolar + UMUM akan selalu ditolak).
        $this->form->fill([
            'entry_date' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Input Transaksi')
                    ->description('Jurnal akan otomatis dibuat & langsung POSTED setelah disimpan.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('entry_date')
                            ->label('Tanggal')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->maxDate(now()->endOfYear())
                            ->disabledDates(fn (): array => $this->closedPeriodSampleDates())
                            ->helperText(fn (): ?string => $this->closedPeriodHint()),

                        Select::make('type')
                            ->label('Jenis Transaksi')
                            ->native(false)
                            ->options(collect(QuickTransactionType::cases())
                                ->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $type = QuickTransactionType::tryFrom((string) $state);
                                if (! $type) return;
                                $allowed = $type->allowedMethods();
                                $set('method', $allowed[0] ?? null);
                                $set('counter_account_id', null);
                            }),

                        Select::make('method')
                            ->label('Metode / Sumber Dana')
                            ->native(false)
                            ->options(function (callable $get): array {
                                $type = QuickTransactionType::tryFrom((string) $get('type'));
                                if (! $type) return [];
                                $labels = [
                                    'kas'    => 'Tunai (Kas)',
                                    'bank'   => 'Transfer (Bank)',
                                    'utang'  => 'Utang (belum dibayar)',
                                    'nonkas' => 'Non-kas (penyesuaian)',
                                ];
                                return collect($type->allowedMethods())
                                    ->mapWithKeys(fn ($m) => [$m => $labels[$m] ?? $m])
                                    ->all();
                            })
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('counter_account_id', null)),

                        Select::make('counter_account_id')
                            ->label(function (callable $get): string {
                                $type = QuickTransactionType::tryFrom((string) $get('type'));
                                if ($type?->isPenyusutan()) return 'Kategori Akumulasi Penyusutan';
                                return match ($get('method')) {
                                    'kas', 'bank' => 'Akun Kas / Bank',
                                    'utang'       => 'Akun Utang Vendor',
                                    default       => 'Akun Lawan',
                                };
                            })
                            ->options(function (callable $get): array {
                                $type = QuickTransactionType::tryFrom((string) $get('type'));
                                $method = (string) $get('method');
                                if (! $type || ! $method) return [];

                                return app(QuickTransactionService::class)
                                    ->counterAccountOptions(Filament::getTenant(), $type, $method)
                                    ->mapWithKeys(fn (Account $a) => [
                                        $a->id => "[{$a->code}] {$a->name}",
                                    ])
                                    ->all();
                            })
                            ->required()
                            ->searchable()
                            ->live()
                            ->helperText(function (callable $get): ?string {
                                $type = QuickTransactionType::tryFrom((string) $get('type'));
                                if ($type?->isPenyusutan()) {
                                    return 'Pilih kategori aset yang disusutkan. Jurnal nonkas: Beban Penyusutan → Akumulasi.';
                                }
                                if (($get('method') === 'kas' || $get('method') === 'bank')
                                    && empty(app(QuickTransactionService::class)
                                        ->counterAccountOptions(Filament::getTenant(), $type ?? QuickTransactionType::BebanSolar, $get('method'))
                                        ->toArray())) {
                                    return '⚠️ Belum ada akun Kas/Bank di COA. Tambahkan sub-akun di Master Data → COA.';
                                }
                                return null;
                            }),

                        Select::make('business_unit_id')
                            ->label('Lini Bisnis (alokasi)')
                            ->native(false)
                            ->options(fn (): array => BusinessUnit::query()
                                ->where('company_id', Filament::getTenant()->id)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (BusinessUnit $bu) => [$bu->id => "[{$bu->code}] {$bu->name}"])
                                ->all())
                            ->required(),

                        TextInput::make('amount')
                            ->label('Nominal (Rp)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->step(1)
                            ->prefix('Rp'),

                        Textarea::make('description')
                            ->label('Keterangan')
                            ->rows(2)
                            ->columnSpanFull()
                            ->maxLength(500)
                            ->placeholder('Opsional — kosongkan untuk pakai label default jenis transaksi'),
                    ]),
            ]);
    }

    /**
     * Submit handler dipanggil dari blade button via wire:click="submit".
     */
    public function submit(): void
    {
        $state = $this->form->getState();

        $type = QuickTransactionType::tryFrom((string) ($state['type'] ?? ''));
        if (! $type) {
            $this->addError('data.type', 'Jenis transaksi tidak valid.');
            return;
        }

        // Counter account dengan tenant-scoped query
        $counterAccount = Account::query()
            ->where('company_id', Filament::getTenant()->id)
            ->find($state['counter_account_id'] ?? null);

        if (! $counterAccount) {
            $this->addError('data.counter_account_id', 'Akun lawan wajib dipilih.');
            return;
        }

        try {
            $journal = app(QuickTransactionService::class)->post(
                company:        Filament::getTenant(),
                type:           $type,
                counterAccount: $counterAccount,
                amount:         (float) $state['amount'],
                date:           Carbon::parse($state['entry_date']),
                businessUnitId: $state['business_unit_id'] ?? null,
                description:    $state['description'] ?? null,
                method:         $state['method'] ?? null,
            );

            Notification::make()
                ->title('✅ Transaksi tersimpan')
                ->body("Jurnal {$journal->entry_number} berhasil di-post: Rp " . number_format((float) $journal->total_amount, 0, ',', '.'))
                ->success()
                ->send();

            // Reset bersih — sisakan hanya context yang masih relevan
            $this->form->fill([
                'entry_date'       => $state['entry_date'],
                'type'             => $type->value,
                'method'           => $state['method'],
                'business_unit_id' => $state['business_unit_id'] ?? null,
                'counter_account_id' => null,
                'amount'           => null,
                'description'      => null,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Attach error ke field spesifik supaya user tahu field mana yang bermasalah
            foreach ($e->errors() as $field => $messages) {
                $this->addError("data.{$field}", is_array($messages) ? $messages[0] : (string) $messages);
            }
            Notification::make()
                ->title('❌ Gagal posting')
                ->body(collect($e->errors())->flatten()->first() ?? 'Validasi gagal.')
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('❌ Error tak terduga')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => JournalEntry::query()
                ->where('company_id', Filament::getTenant()->id)
                ->where('document_type', 'quick_tx')
                ->with(['lines.account', 'businessUnit'])
                ->latest('entry_date')
                ->latest('id'))
            ->columns([
                TextColumn::make('entry_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('document_number')
                    ->label('No. Dokumen')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('entry_number')
                    ->label('No. Jurnal')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(60)
                    ->tooltip(fn (JournalEntry $record) => $record->description)
                    ->searchable(),
                TextColumn::make('businessUnit.code')
                    ->label('Lini')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'RENT' => 'warning',
                        'ARMD' => 'success',
                        'MATL' => 'info',
                        'BONG' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_amount')
                    ->label('Nominal')
                    ->money('IDR', locale: 'id')
                    ->alignRight()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'posted' => 'success',
                        'void'   => 'gray',
                        default  => 'warning',
                    }),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        Select::make('year')
                            ->label('Tahun')
                            ->options(collect(range(2020, (int) now()->year + 1))
                                ->mapWithKeys(fn ($y) => [$y => (string) $y]))
                            ->default((int) now()->year),
                        Select::make('month')
                            ->label('Bulan')
                            ->options([
                                1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
                                7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember',
                            ])
                            ->default((int) now()->month),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        if (! empty($data['year']))  $q->where('period_year', $data['year']);
                        if (! empty($data['month'])) $q->where('period_month', $data['month']);
                        return $q;
                    }),
                SelectFilter::make('status')
                    ->options([
                        'posted' => 'Posted',
                        'void'   => 'Void',
                    ]),
                SelectFilter::make('business_unit_id')
                    ->label('Lini Bisnis')
                    ->options(fn (): array => BusinessUnit::query()
                        ->where('company_id', Filament::getTenant()->id)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (BusinessUnit $bu) => [$bu->id => "[{$bu->code}] {$bu->name}"])
                        ->all()),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Detail')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (JournalEntry $record) => "Detail Jurnal {$record->entry_number}")
                    ->modalContent(fn (JournalEntry $record) => view('filament.pages.partials.quick-tx-detail', ['journal' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Action::make('void')
                    ->label('Void')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Void transaksi ini?')
                    ->modalDescription('Akan dibuat jurnal pembalik otomatis. Aksi ini tidak bisa di-undo.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan void')
                            ->rows(2)
                            ->required()
                            ->minLength(5)
                            ->maxLength(500),
                    ])
                    ->visible(fn (JournalEntry $record) => $record->isPosted())
                    ->action(function (JournalEntry $record, array $data) {
                        try {
                            app(JournalService::class)->void($record, $data['reason'] ?? null);
                            Notification::make()
                                ->title('✅ Transaksi di-void')
                                ->body("Jurnal pembalik telah dibuat untuk {$record->entry_number}.")
                                ->success()
                                ->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('❌ Gagal void')
                                ->body(collect($e->errors())->flatten()->first() ?? 'Validasi gagal.')
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            report($e);
                            Notification::make()
                                ->title('❌ Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('entry_date', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    // ============================================================
    // Helper untuk DatePicker disabledDates (closed period guard)
    // ============================================================

    /**
     * Return array tanggal yang ada di periode tertutup (untuk current year only,
     * supaya tidak loop seluruh history). DatePicker disabledDates expects array of date strings.
     *
     * Implementasi sederhana: ambil semua tanggal di bulan-bulan yang status = closed.
     */
    protected function closedPeriodSampleDates(): array
    {
        $tenant = Filament::getTenant();
        $year = (int) now()->year;

        $closedMonths = AccountingPeriod::query()
            ->where('company_id', $tenant->id)
            ->where('period_year', $year)
            ->where('status', 'closed')
            ->pluck('period_month')
            ->all();

        if (empty($closedMonths)) return [];

        $dates = [];
        foreach ($closedMonths as $m) {
            $start = Carbon::create($year, $m, 1);
            $end = $start->copy()->endOfMonth();
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dates[] = $d->toDateString();
            }
        }
        return $dates;
    }

    protected function closedPeriodHint(): ?string
    {
        $tenant = Filament::getTenant();
        $year = (int) now()->year;

        $count = AccountingPeriod::query()
            ->where('company_id', $tenant->id)
            ->where('period_year', $year)
            ->where('status', 'closed')
            ->count();

        return $count > 0
            ? "⚠️ {$count} periode tahun {$year} telah ditutup — tanggalnya tidak bisa dipilih."
            : null;
    }
}
