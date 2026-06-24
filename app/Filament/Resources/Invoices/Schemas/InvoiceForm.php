<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->default(fn () => now()->addDays(30))
                            ->native(false),

                        Select::make('client_id')
                            ->label('Pelanggan')
                            ->relationship('client', 'name', fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('business_unit_id')
                            ->label('Lini Bisnis')
                            ->relationship('businessUnit', 'name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}] {$record->name}")
                            ->searchable()
                            ->preload()
                            ->live()
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
                                    ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])
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
                            ->numeric()
                            ->default(0)
                            ->prefix('Rp')
                            ->minValue(0),

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
            ]);
    }
}
