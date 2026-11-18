<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Account;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\ProjectUnitLog;
use App\Services\Accounting\ProjectUnitLogService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Pemakaian alat berat & truk SENDIRI di proyek borongan, per hari.
 * Simpan/ubah/hapus lewat ProjectUnitLogService (jurnal biaya + penyusutan).
 */
class UnitLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'unitLogs';

    protected static ?string $title = 'Pemakaian Unit Harian';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                DatePicker::make('log_date')->label('Tanggal')->required()->default(now())->native(false),

                Select::make('asset_id')
                    ->label('Unit (alat berat / truk)')
                    ->required()->native(false)->searchable()->live()
                    ->options(fn () => Asset::query()->where('status', '!=', 'non_aktif')->orderBy('asset_code')->get()
                        ->mapWithKeys(fn ($a) => [$a->id => "[{$a->asset_code}] {$a->name}"])->all()),

                Select::make('operator_id')
                    ->label('Operator / Supir')
                    ->native(false)->searchable()
                    ->options(fn () => Employee::query()->where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn ($e) => [$e->id => "[{$e->employee_id}] {$e->name}"])->all()),

                TextInput::make('jam_kerja')->label('Jam Kerja (alat berat)')->numeric()->minValue(0)->step(0.5)->suffix('jam')->live(onBlur: true),
                TextInput::make('rit_count')->label('Jumlah Rit (truk)')->numeric()->minValue(0)->step(1)->suffix('rit')->live(onBlur: true),
                TextInput::make('solar_liter')->label('Solar')->numeric()->minValue(0)->step(0.1)->suffix('L')->live(onBlur: true)
                    ->helperText('Kosongkan harga di bawah untuk memakai harga solar default PT.'),

                TextInput::make('harga_solar')->label('Harga Solar (Rp/L)')->rupiah()->placeholder('Default PT')->live(onBlur: true),
                TextInput::make('gaji')->label('Gaji Operator/Supir')->rupiah()->live(onBlur: true),
                TextInput::make('uang_makan')->label('Uang Makan')->rupiah()->live(onBlur: true),
                TextInput::make('premi')->label('Premi / Uang Jalan')->rupiah()->live(onBlur: true),
                TextInput::make('biaya_lain')->label('Lain-lain')->rupiah()->live(onBlur: true),
                TextInput::make('biaya_lain_ket')->label('Keterangan Lain-lain')->maxLength(150),

                Select::make('cash_account_id')
                    ->label('Dibayar dari Kas')
                    ->native(false)
                    ->options(fn () => ($t = Filament::getTenant()) ? Account::cashAccounts($t->getKey())
                        ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])->all() : [])
                    ->helperText('Kosong = kas utama.'),

                Placeholder::make('preview')
                    ->label('Total Biaya Hari Ini')
                    ->columnSpan(2)
                    ->content(function (Get $get): HtmlString {
                        $money = fn ($v) => (float) (app(\App\Services\Import\AssetImportService::class)->money($v) ?? 0);
                        $harga = $money($get('harga_solar')) ?: (float) (Filament::getTenant()?->harga_solar_default ?? 6800);
                        $bbm   = (float) $get('solar_liter') * $harga;
                        $total = $bbm + $money($get('gaji')) + $money($get('uang_makan')) + $money($get('premi')) + $money($get('biaya_lain'));

                        return new HtmlString(sprintf('<strong>Rp %s</strong> <span style="opacity:.7">(BBM Rp %s)</span>',
                            number_format($total, 0, ',', '.'), number_format($bbm, 0, ',', '.')));
                    }),

                Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'operator']))
            ->defaultSort('log_date', 'desc')
            ->emptyStateHeading('Belum ada pemakaian unit')
            ->emptyStateDescription('Catat alat berat & truk sendiri yang bekerja di proyek ini per hari — biayanya menjadi biaya proyek di Laporan Margin Proyek.')
            ->columns([
                TextColumn::make('log_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('asset.asset_code')->label('Unit')->badge()
                    ->description(fn (ProjectUnitLog $r) => $r->asset?->name),
                TextColumn::make('operator.name')->label('Operator/Supir')->placeholder('—'),
                TextColumn::make('usage')->label('Pemakaian')->state(fn (ProjectUnitLog $r) => $r->usageLabel()),
                TextColumn::make('biaya_bbm')->label('BBM')->money('IDR', 0)->alignEnd()->toggleable(),
                TextColumn::make('total_biaya')->label('Total Biaya')->money('IDR', 0)->alignEnd()->weight('bold'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Catat Pemakaian')
                    ->visible(fn (): bool => $this->getOwnerRecord()->isBerjalan())
                    ->using(fn (array $data): Model => $this->run(fn () => app(ProjectUnitLogService::class)->create($this->getOwnerRecord(), $data))),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (ProjectUnitLog $record, array $data): Model => $this->run(fn () => app(ProjectUnitLogService::class)->update($record, $data))),
                DeleteAction::make()
                    ->using(fn (ProjectUnitLog $record) => $this->run(fn () => app(ProjectUnitLogService::class)->delete($record) ?? true)),
            ]);
    }

    /** Validasi service → notifikasi + batalkan aksi (bukan halaman error). */
    private function run(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Gagal menyimpan')
                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();

            throw (new \Filament\Support\Exceptions\Halt())->rollBackDatabaseTransaction();
        }
    }
}
