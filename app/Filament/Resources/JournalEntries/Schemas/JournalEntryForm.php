<?php

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class JournalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Header Jurnal')
                    ->columns(2)
                    ->schema([
                        TextInput::make('entry_number')
                            ->label('No. Jurnal')
                            ->placeholder('Otomatis')
                            ->disabled()
                            ->dehydrated(false),

                        DatePicker::make('entry_date')
                            ->label('Tanggal')
                            ->default(now())
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $date = \Carbon\Carbon::parse($state);
                                    $set('period_year', $date->year);
                                    $set('period_month', $date->month);
                                }
                            }),

                        TextInput::make('document_number')
                            ->label('No. Bukti')
                            ->maxLength(50)
                            ->placeholder('INV-0001-01'),

                        Select::make('document_type')
                            ->label('Jenis Bukti')
                            ->options([
                                'manual' => 'Manual',
                                'invoice' => 'Invoice',
                                'bkm' => 'BKM (Kas Masuk)',
                                'bkk' => 'BKK (Kas Keluar)',
                                'jual_beli' => 'Jual Beli',
                                'penyusutan' => 'Penyusutan',
                                'penyesuaian' => 'Penyesuaian',
                                'penutup' => 'Jurnal Penutup',
                                'saldo_awal' => 'Saldo Awal',
                            ])
                            ->default('manual')
                            ->required()
                            ->native(false),

                        Select::make('business_unit_id')
                            ->label('Lini Bisnis')
                            ->relationship('businessUnit', 'name')
                            ->getOptionLabelFromRecordUsing(fn($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        Textarea::make('description')
                            ->label('Memo / Keterangan')
                            ->rows(2)
                            ->columnSpanFull(),

                        Hidden::make('period_year')->default(now()->year),
                        Hidden::make('period_month')->default(now()->month),
                    ]),

                Section::make('Baris Jurnal (Debit & Kredit)')
                    ->description('Total Debit harus sama dengan Total Kredit. Tiap baris hanya boleh diisi salah satu: Debit ATAU Kredit.')
                    ->schema([
                        Repeater::make('lines')
                            ->label('')
                            ->relationship()
                            ->live()
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->orderColumn('sort_order')
                            ->minItems(2)
                            ->addActionLabel('+ Tambah Baris')
                            ->columns(2)
                            ->schema([
                                Select::make('account_id')
                                    ->label('Akun')
                                    ->options(function () {
                                        $tenant = Filament::getTenant();
                                        // Filter ke akun POSTABLE saja (tidak punya children = leaf)
                                        $query = Account::query()
                                            ->where('is_active', true)
                                            ->postable();

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
                                    ->required()
                                    ->live()
                                    ->helperText('Hanya akun leaf (tanpa sub-akun) yang muncul. Akun HEADER otomatis disembunyikan.')
                                    ->columnSpan(1),

                                TextInput::make('description')
                                    ->label('Keterangan Baris')
                                    ->placeholder('opsional')
                                    ->live(onBlur: true)
                                    ->columnSpan(1),

                                TextInput::make('debit')
                                    ->label('Debit (Rp)')
                                    ->default(0)
                                    ->prefix('Rp')
                                    ->inputMode('numeric')
                                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 0, ',', '.') : '0')
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace('.', '', (string) $state))
                                    ->extraInputAttributes([
                                        'x-on:input' => "\$el.value = \$el.value.replace(/[^0-9]/g, '').replace(/\\B(?=(\\d{3})+(?!\\d))/g, '.')",
                                    ])
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $val = (float) str_replace('.', '', (string) $state);
                                        if ($val > 0) {
                                            $set('kredit', '0');
                                        }
                                    })
                                    ->columnSpan(1),

                                TextInput::make('kredit')
                                    ->label('Kredit (Rp)')
                                    ->default(0)
                                    ->prefix('Rp')
                                    ->inputMode('numeric')
                                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 0, ',', '.') : '0')
                                    ->dehydrateStateUsing(fn ($state) => (float) str_replace('.', '', (string) $state))
                                    ->extraInputAttributes([
                                        'x-on:input' => "\$el.value = \$el.value.replace(/[^0-9]/g, '').replace(/\\B(?=(\\d{3})+(?!\\d))/g, '.')",
                                    ])
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $val = (float) str_replace('.', '', (string) $state);
                                        if ($val > 0) {
                                            $set('debit', '0');
                                        }
                                    })
                                    ->columnSpan(1),
                            ]),

                        Placeholder::make('balance_check')
                            ->label('')
                            ->content(function (Get $get): HtmlString {
                                $lines = $get('lines') ?? [];
                                $debit = 0.0;
                                $kredit = 0.0;

                                // Helper: lepas titik (separator ribuan dari mask) sebelum cast float
                                $parse = fn ($v) => (float) str_replace('.', '', (string) ($v ?? 0));

                                foreach ($lines as $line) {
                                    $debit  += $parse($line['debit']  ?? 0);
                                    $kredit += $parse($line['kredit'] ?? 0);
                                }

                                $selisih = round($debit - $kredit, 2);
                                $hasInput = ($debit > 0 || $kredit > 0);
                                $balanced = $hasInput && $selisih === 0.0;

                                // Tiga state: empty (netral), balanced (hijau), unbalanced (merah)
                                if (!$hasInput) {
                                    $state = 'empty';
                                    $statusLabel = 'Belum ada nilai';
                                } elseif ($balanced) {
                                    $state = 'balanced';
                                    $statusLabel = '&#10003; Balance';
                                } else {
                                    $state = 'unbalanced';
                                    $statusLabel = '&#10007; Tidak Balance';
                                }

                                $fmt = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');

                                return new HtmlString(<<<HTML
                                    <div class="je-balance je-balance-{$state}">
                                        <div class="je-balance-item">
                                            <span class="je-balance-label">Total Debit</span>
                                            <span class="je-balance-value">{$fmt($debit)}</span>
                                        </div>
                                        <div class="je-balance-item">
                                            <span class="je-balance-label">Total Kredit</span>
                                            <span class="je-balance-value">{$fmt($kredit)}</span>
                                        </div>
                                        <div class="je-balance-item">
                                            <span class="je-balance-label">Selisih</span>
                                            <span class="je-balance-value">{$fmt(abs($selisih))}</span>
                                        </div>
                                        <div class="je-balance-item je-balance-status">
                                            <span class="je-balance-label">Status</span>
                                            <span class="je-balance-pill">{$statusLabel}</span>
                                        </div>
                                    </div>
                                    HTML);
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
