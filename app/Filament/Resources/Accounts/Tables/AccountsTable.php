<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Enums\AccountRole;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Services\CoaImportService;
use Illuminate\Support\HtmlString;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class AccountsTable
{
    private const CATEGORY_LABELS = [
        'aset'       => 'Aset',
        'kewajiban'  => 'Kewajiban',
        'ekuitas'    => 'Ekuitas',
        'pendapatan' => 'Pendapatan',
        'beban'      => 'Beban',
        'penutup'    => 'Akun Penutup',
    ];

    private const CASH_FLOW_LABELS = [
        'operasi'   => 'Operasi',
        'investasi' => 'Investasi',
        'pendanaan' => 'Pendanaan',
        'non_kas'   => 'Non-Kas',
    ];

    public static function configure(Table $table): Table
    {
        // Struktur pohon COA dihitung SEKALI per request (1 query), bukan
        // isHeader() per baris — dulu 3+ query per baris (nama, tipe, migrasi).
        $tree = null;
        $treeOf = function () use (&$tree): array {
            return $tree ??= self::buildTree();
        };
        $isHeader = fn (Account $record): bool => isset($treeOf()['headers'][$record->code]);
        $isGuessed = fn (Account $record): bool =>
            str_starts_with((string) $record->notes, CoaImportService::GUESSED_ROLE_PREFIX);

        return $table
            ->defaultSort('code', 'asc')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['class' => 'coa-code']),

                // Hierarki ditunjukkan lewat indentasi NAMA (seperti pohon),
                // bukan prefix "└─" di kode yang membuat kolom kode tidak rata.
                TextColumn::make('name')
                    ->label('Nama Akun')
                    ->searchable()
                    ->wrap()
                    ->html()
                    ->formatStateUsing(function ($state, Account $record) use ($treeOf, $isHeader): HtmlString {
                        $depth = min($treeOf()['depth'][$record->code] ?? 0, 6);

                        return new HtmlString(sprintf(
                            '<span class="coa-name%s" style="padding-left: %.2frem">%s</span>',
                            $isHeader($record) ? ' coa-name-header' : '',
                            $depth * 1.25,
                            e($state),
                        ));
                    }),

                TextColumn::make('hierarchy_status')
                    ->label('Tipe')
                    ->getStateUsing(fn (Account $record) => $isHeader($record) ? 'Induk' : 'Transaksi')
                    ->badge()
                    ->color(fn ($state) => $state === 'Induk' ? 'gray' : 'success')
                    ->tooltip(fn ($state) => $state === 'Induk'
                        ? 'Akun induk: hanya pengelompok, tidak bisa dipakai di jurnal. Saldo = jumlah sub-akunnya.'
                        : 'Akun transaksi: bisa dipakai di jurnal.'),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => self::CATEGORY_LABELS[$state] ?? $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'aset'       => 'info',
                        'kewajiban'  => 'warning',
                        'ekuitas'    => 'success',
                        'pendapatan' => 'success',
                        'beban'      => 'danger',
                        default      => 'gray',
                    }),

                TextColumn::make('sub_category')
                    ->label('Sub-Kategori')
                    ->formatStateUsing(fn (?string $state) => Account::allValidSubCategories()[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('normal_balance')
                    ->label('Saldo Normal')
                    ->formatStateUsing(fn (?string $state) => $state === 'kredit' ? 'Kredit' : 'Debit')
                    ->color('gray'),

                TextColumn::make('cash_flow_category')
                    ->label('Arus Kas')
                    ->formatStateUsing(fn (?string $state) => self::CASH_FLOW_LABELS[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                // Peran di akun INDUK hanya diwariskan ke sub-akun baru — jurnal
                // otomatis selalu memakai akun transaksi. Karena itu ditampilkan
                // redup, supaya tidak dikira akun induk yang dipakai menjurnal.
                TextColumn::make('role')
                    ->label('Peran')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state instanceof AccountRole ? $state->label() : $state)
                    ->color(fn (Account $record) => match (true) {
                        $isGuessed($record) => 'warning',
                        $isHeader($record)  => 'gray',
                        default             => 'primary',
                    })
                    ->icon(fn (Account $record) => $isGuessed($record) ? Heroicon::OutlinedExclamationTriangle : null)
                    ->tooltip(fn (Account $record) => match (true) {
                        $isGuessed($record) => 'Ditebak dari nama akun saat import. Periksa, lalu hapus catatannya di form Edit.',
                        $isHeader($record)  => 'Peran di akun induk hanya diwariskan ke sub-akun baru.',
                        default             => 'Penanda akun untuk jurnal otomatis & laporan.',
                    })
                    ->toggleable(),

                TextColumn::make('tax_type')
                    ->label('Pajak')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('perhatian')
                    ->label('Perlu Diperiksa')
                    ->options([
                        'tebakan'     => 'Peran hasil tebakan import',
                        'tanpa_peran' => 'Akun transaksi tanpa Peran',
                    ])
                    // Nama parameter WAJIB $query/$data — Filament menyuntik berdasarkan nama.
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'tebakan'     => $query->where('notes', 'like', CoaImportService::GUESSED_ROLE_PREFIX . '%'),
                            'tanpa_peran' => $query->postable()->whereNull('role'),
                            default       => $query,
                        };
                    }),

                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(self::CATEGORY_LABELS),

                SelectFilter::make('hierarchy')
                    ->label('Tipe Hierarki')
                    ->options([
                        'header' => 'HEADER (punya sub-akun)',
                        'leaf'   => 'LEAF / CHILD (bisa di-post)',
                    ])
                    ->query(function ($query, array $data) {
                        if (! ($data['value'] ?? null)) return $query;
                        if ($data['value'] === 'header') return $query->headers();
                        return $query->postable();
                    }),

                TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->recordActions([
                // Action "Tambah Sub-Akun" — buat child dari record ini
                // Hanya untuk yang boleh membuat akun — dulu tombol tampil ke semua
                // user lalu berakhir di halaman 403 (Forbidden).
                Action::make('add_child')
                    ->label('Sub-Akun')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('gray')
                    ->tooltip('Buat sub-akun di bawah akun ini')
                    ->visible(fn () => AccountResource::canCreate())
                    ->url(function (Account $record) {
                        $tenant = Filament::getTenant();
                        return AccountResource::getUrl('create', [
                            'parent_code' => $record->code,
                            'tenant'      => $tenant,
                        ]);
                    }),

                // Action: Migrasi transaksi legacy parent → child default
                Action::make('migrate_to_child')
                    ->label('Migrasi ke Sub-Akun')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('warning')
                    // Hanya untuk HEADER yang punya legacy journal lines (dihitung
                    // sekali di buildTree) dan user yang boleh mengubah akun.
                    ->visible(fn (Account $record) => isset($treeOf()['headersWithLines'][$record->id])
                        && AccountResource::canEdit($record))
                    ->schema([
                        Select::make('target_child_id')
                            ->label('Pindahkan ke Sub-Akun')
                            ->required()
                            ->options(function (Account $record) {
                                return $record->children()
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
                                    ->toArray();
                            })
                            ->searchable()
                            ->helperText('Semua jurnal line yang post ke akun parent akan dipindah ke sub-akun ini.'),
                    ])
                    ->modalHeading(fn (Account $record) =>
                        'Migrasi Transaksi Legacy: ' . $record->code)
                    ->modalDescription(function (Account $record) {
                        $count = JournalEntryLine::where('account_id', $record->id)->count();
                        return "Ada {$count} jurnal line yang post langsung ke akun parent [{$record->code}] {$record->name}. "
                            . "Aksi ini akan memindahkan semua transaksi tersebut ke sub-akun yang Anda pilih. "
                            . "Saldo akhir tetap sama, hanya berubah secara representasi.";
                    })
                    ->requiresConfirmation()
                    ->action(function (array $data, Account $record) {
                        $targetChild = Account::withoutGlobalScopes()->find($data['target_child_id']);

                        if (! $targetChild || $targetChild->parent_code !== $record->code) {
                            Notification::make()
                                ->title('Sub-akun target tidak valid')
                                ->danger()->send();
                            return;
                        }

                        DB::transaction(function () use ($record, $targetChild) {
                            $count = JournalEntryLine::where('account_id', $record->id)
                                ->update(['account_id' => $targetChild->id]);

                            Notification::make()
                                ->title("✅ {$count} jurnal line dipindahkan")
                                ->body("Dari [{$record->code}] → [{$targetChild->code}] {$targetChild->name}")
                                ->success()->send();
                        });
                    }),

                // Peran tebakan import sudah diperiksa & benar → hilangkan tanda ⚠
                // (dulu hanya hilang bila catatan dihapus manual).
                Action::make('confirm_role')
                    ->label('Peran Benar')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->tooltip('Peran hasil tebakan import sudah diperiksa dan benar')
                    ->visible($isGuessed)
                    ->authorize(fn (Account $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (Account $record) {
                        $record->update(['notes' => CoaImportService::stripGuessedNote($record->notes)]);
                        \Filament\Notifications\Notification::make()->success()->title("Peran {$record->code} dikonfirmasi")->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('confirm_roles')
                        ->label('Tandai Peran Sudah Diperiksa')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Tanda "Peran hasil tebakan import" pada akun terpilih dihapus. Pastikan Peran-nya sudah benar.')
                        ->authorize(fn (): bool => auth()->user()?->can('create', Account::class) ?? false)
                        ->action(function ($records) {
                            $n = 0;
                            foreach ($records as $record) {
                                if (str_contains((string) $record->notes, CoaImportService::GUESSED_ROLE_PREFIX)
                                    && auth()->user()->can('update', $record)) {
                                    $record->update(['notes' => CoaImportService::stripGuessedNote($record->notes)]);
                                    $n++;
                                }
                            }
                            \Filament\Notifications\Notification::make()->success()->title("{$n} akun dikonfirmasi")->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Pohon COA tenant aktif dalam 2 query: kode induk, kedalaman tiap akun,
     * dan akun induk yang masih punya jurnal langsung (kandidat migrasi).
     *
     * @return array{headers: array<string, true>, depth: array<string, int>, headersWithLines: array<int, true>}
     */
    private static function buildTree(): array
    {
        $rows = Account::query()->get(['id', 'code', 'parent_code']);

        $parentOf = $rows->pluck('parent_code', 'code')->all();
        $headers  = array_fill_keys(array_filter(array_unique(array_values($parentOf))), true);

        $depth = [];
        foreach (array_keys($parentOf) as $code) {
            $d = 0;
            $seen = [];
            $p = $parentOf[$code] ?? null;
            // Batas & $seen: data rusak (induk melingkar) tidak boleh membuat loop tak berujung.
            while ($p !== null && $p !== '' && isset($parentOf[$p]) && ! isset($seen[$p]) && $d < 10) {
                $seen[$p] = true;
                $d++;
                $p = $parentOf[$p];
            }
            $depth[$code] = $d;
        }

        $headerIds = $rows->filter(fn ($r) => isset($headers[$r->code]))->pluck('id')->all();
        $withLines = $headerIds === [] ? [] : JournalEntryLine::query()
            ->whereIn('account_id', $headerIds)
            ->distinct()
            ->pluck('account_id')
            ->all();

        return [
            'headers'          => $headers,
            'depth'            => $depth,
            'headersWithLines' => array_fill_keys($withLines, true),
        ];
    }
}
