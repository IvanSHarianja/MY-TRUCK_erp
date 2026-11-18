<?php

namespace App\Filament\Resources\Debts;

use App\Filament\Resources\Debts\Pages\CreateDebt;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Debts\Pages\ViewDebt;
use App\Filament\Resources\Debts\RelationManagers\InstallmentsRelationManager;
use App\Models\Account;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/** Hutang & cicilan ke bank / leasing / vendor dengan jadwal jatuh tempo. */
class DebtResource extends Resource
{
    protected static ?string $model = Debt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Hutang & Cicilan';

    protected static ?string $modelLabel = 'Hutang';

    protected static ?string $pluralModelLabel = 'Hutang & Cicilan';

    protected static ?int $navigationSort = 31;

    /** Jumlah cicilan telat / jatuh tempo ≤ 7 hari. */
    public static function getNavigationBadge(): ?string
    {
        if (! Filament::getTenant()) {
            return null;
        }
        $n = DebtInstallment::query()->where('status', 'belum')
            ->whereDate('due_date', '<=', today()->addDays(DebtInstallment::SOON_DAYS))->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $overdue = Filament::getTenant() && DebtInstallment::query()->where('status', 'belum')
            ->whereDate('due_date', '<', today())->exists();

        return $overdue ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Cicilan telat / jatuh tempo dalam ' . DebtInstallment::SOON_DAYS . ' hari';
    }

    public static function form(Schema $schema): Schema
    {
        $tenantId = fn () => Filament::getTenant()?->getKey() ?? 0;
        $money = fn ($v) => (float) (app(\App\Services\Import\AssetImportService::class)->money($v) ?? 0);

        return $schema
            ->columns(1)
            ->components([
                Section::make('Kreditur')
                    ->columns(3)
                    ->schema([
                        Select::make('jenis')->label('Jenis')->required()->native(false)->options(Debt::JENIS)->default('bank')->live(),
                        TextInput::make('kreditur')->label('Nama Kreditur')->required()->maxLength(150)
                            ->placeholder('contoh: Bank BRI Cab. Medan / Mandiri Tunas Finance'),
                        Select::make('vendor_id')->label('Vendor (opsional)')->native(false)->searchable()
                            ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->visible(fn (Get $get) => $get('jenis') === 'vendor')
                            ->afterStateUpdated(fn ($state, Set $set) => $state ? $set('kreditur', Vendor::find($state)?->name) : null)
                            ->live(),
                        Select::make('payable_account_id')->label('Akun Hutang')->required()->native(false)->searchable()
                            ->options(fn () => Account::query()->where('company_id', $tenantId())->where('category', 'kewajiban')
                                ->where('is_active', true)->postable()->orderBy('code')->get()
                                ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])->all())
                            ->helperText('Mis. Utang Bank, Utang Leasing, Utang Vendor.')
                            ->columnSpan(2),
                    ]),

                Section::make('Pokok, Bunga & Jadwal')
                    ->columns(3)
                    ->schema([
                        TextInput::make('pokok')->label('Pokok Hutang')->required()->rupiah()->live(onBlur: true),
                        TextInput::make('bunga_pct')->label('Bunga Flat')->numeric()->minValue(0)->maxValue(100)->step(0.01)
                            ->default(0)->suffix('% / tahun')->live(onBlur: true),
                        TextInput::make('tenor_bulan')->label('Tenor')->required()->integer()->minValue(1)->maxValue(360)
                            ->default(12)->suffix('bulan')->live(onBlur: true)
                            ->helperText('Hutang vendor sekali bayar: 1.'),
                        DatePicker::make('tanggal_mulai')->label('Tanggal Mulai / Pencairan')->required()->native(false)->default(now()),
                        DatePicker::make('jatuh_tempo_pertama')->label('Jatuh Tempo Cicilan Pertama')->required()->native(false)
                            ->default(now()->addMonthNoOverflow()),
                        Select::make('disbursement_account_id')->label('Dana Diterima di (opsional)')->native(false)
                            ->options(fn () => Account::cashAccounts($tenantId())->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])->all())
                            ->helperText('Isi bila dana pinjaman baru masuk rekening → jurnal Dr Kas / Cr Hutang. Kosongkan untuk hutang lama yang sudah tercatat.')
                            ->visibleOn('create'),
                        Placeholder::make('preview')
                            ->label('Perkiraan Cicilan per Bulan')
                            ->columnSpanFull()
                            ->visibleOn('create')
                            ->content(function (Get $get) use ($money): HtmlString {
                                $pokok = $money($get('pokok'));
                                $tenor = max(1, (int) $get('tenor_bulan'));
                                $bunga = round($pokok * (float) $get('bunga_pct') / 100 / 12);
                                $per = floor($pokok / $tenor);

                                return new HtmlString(sprintf(
                                    '<strong>Rp %s</strong> <span style="opacity:.7">(pokok Rp %s + bunga Rp %s) × %d bulan · total bunga Rp %s</span>',
                                    number_format($per + $bunga, 0, ',', '.'), number_format($per, 0, ',', '.'),
                                    number_format($bunga, 0, ',', '.'), $tenor, number_format($bunga * $tenor, 0, ',', '.'),
                                ));
                            }),
                        Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull()
                            ->placeholder('No. perjanjian kredit, unit yang dijaminkan, dsb.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['nextInstallment', 'payableAccount'])
                ->withSum(['installments as sisa_pokok' => fn ($i) => $i->where('status', 'belum')], 'pokok')
                ->withCount(['installments', 'installments as lunas_count' => fn ($i) => $i->where('status', 'lunas')]))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Belum ada hutang tercatat')
            ->emptyStateDescription('Catat pinjaman bank, leasing unit, atau hutang vendor beserta jadwal cicilannya. Pembelian material kredit otomatis masuk ke sini.')
            ->columns([
                TextColumn::make('debt_number')->label('No.')->searchable()->weight('bold'),
                TextColumn::make('kreditur')->label('Kreditur')->searchable()
                    ->description(fn (Debt $r) => Debt::JENIS[$r->jenis] ?? $r->jenis),
                TextColumn::make('pokok')->label('Pokok')->money('IDR', 0)->alignEnd()->toggleable(),
                TextColumn::make('sisa_pokok')->label('Sisa Pokok')->money('IDR', 0)->alignEnd()->weight('bold')
                    ->state(fn (Debt $r) => (float) ($r->sisa_pokok ?? 0)),
                TextColumn::make('progress')->label('Cicilan')
                    ->state(fn (Debt $r) => "{$r->lunas_count}/{$r->installments_count} lunas"),
                TextColumn::make('nextInstallment.due_date')->label('Jatuh Tempo Berikutnya')->date('d M Y')->placeholder('—')
                    ->description(fn (Debt $r) => $r->nextInstallment
                        ? $r->nextInstallment->dueLabel() . ' · Rp ' . number_format($r->nextInstallment->total(), 0, ',', '.')
                        : null)
                    ->color(fn (Debt $r) => match ($r->nextInstallment?->urgency()) {
                        'overdue' => 'danger', 'soon' => 'warning', default => null,
                    }),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => Debt::STATUS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'lunas' ? 'success' : 'info'),
            ])
            ->filters([
                SelectFilter::make('jenis')->options(Debt::JENIS),
                SelectFilter::make('status')->options(Debt::STATUS)->default('aktif'),
                Filter::make('perlu_dibayar')->label('Telat / jatuh tempo ≤ 7 hari')
                    ->query(fn (Builder $query) => $query->whereHas('installments', fn ($i) => $i->where('status', 'belum')
                        ->whereDate('due_date', '<=', Carbon::today()->addDays(DebtInstallment::SOON_DAYS)))),
            ])
            ->recordActions([ViewAction::make()->label('Jadwal & Bayar')]);
    }

    public static function getRelations(): array
    {
        return [InstallmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListDebts::route('/'),
            'create' => CreateDebt::route('/create'),
            'view'   => ViewDebt::route('/{record}'),
        ];
    }
}
