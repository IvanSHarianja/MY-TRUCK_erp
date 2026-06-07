<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use App\Services\CompanyTemplateService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Daftarkan PT Baru';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Perusahaan')
                    ->description('Sistem akan otomatis menyediakan COA standar (51 akun) & 5 lini bisnis untuk PT baru.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Perusahaan')
                            ->placeholder('PT Maju Terus')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Slug (URL)')
                            ->placeholder('maju-terus')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Otomatis dibuat dari nama. URL: /admin/{slug}')
                            ->unique('companies', 'slug'),

                        TextInput::make('owner_name')
                            ->label('Nama Pimpinan')
                            ->placeholder('Bapak / Ibu ...')
                            ->maxLength(255),

                        Select::make('fiscal_year')
                            ->label('Tahun Buku')
                            ->options(collect(range(now()->year - 2, now()->year + 1))
                                ->mapWithKeys(fn ($y) => [$y => (string) $y]))
                            ->default(now()->year)
                            ->required()
                            ->native(false),

                        DatePicker::make('fiscal_start')
                            ->label('Awal Periode Buku')
                            ->default(now()->startOfYear())
                            ->required()
                            ->native(false),

                        DatePicker::make('fiscal_end')
                            ->label('Akhir Periode Buku')
                            ->default(now()->endOfYear())
                            ->required()
                            ->native(false),
                    ]),

                Section::make('Kontak (Opsional)')
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone')->label('Telepon')->tel()->maxLength(30),
                        TextInput::make('email')->label('Email')->email()->maxLength(255),
                        Textarea::make('address')->label('Alamat')->rows(2)->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    protected function handleRegistration(array $data): Company
    {
        $company = Company::create([
            'name'         => $data['name'],
            'slug'         => $data['slug'],
            'owner_name'   => $data['owner_name'] ?? null,
            'fiscal_year'  => $data['fiscal_year'],
            'fiscal_start' => $data['fiscal_start'],
            'fiscal_end'   => $data['fiscal_end'],
            'phone'        => $data['phone']   ?? null,
            'email'        => $data['email']   ?? null,
            'address'      => $data['address'] ?? null,
            'is_active'    => true,
        ]);

        // Attach user yang sedang login sebagai owner
        $company->users()->attach(auth()->id(), [
            'role'      => 'owner',
            'is_active' => true,
        ]);

        // Auto-seed COA + Business Units
        app(CompanyTemplateService::class)->seedDefaults($company);

        return $company;
    }
}
