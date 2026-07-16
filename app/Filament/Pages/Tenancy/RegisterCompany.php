<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use App\Services\CompanyTemplateService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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

    /**
     * Heading dinamis: user baru (0 tenant) dapat sapaan welcome,
     * user existing (mau tambah company) dapat label singkat.
     */
    public function getHeading(): string
    {
        if ($this->isFirstTimeUser()) {
            return '👋 Selamat Datang di MY-TRUCK';
        }
        return 'Daftarkan Perusahaan Baru';
    }

    public function getSubheading(): ?string
    {
        if ($this->isFirstTimeUser()) {
            return 'Anda belum terdaftar di perusahaan mana pun. Silakan daftarkan perusahaan pertama Anda.';
        }
        return 'Perusahaan baru akan otomatis mendapat COA standar dan lini bisnis default.';
    }

    /**
     * Cek apakah user login belum punya company sama sekali.
     * Dipakai untuk conditional welcome message.
     */
    protected function isFirstTimeUser(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        return $user->getTenants(Filament::getPanel('admin'))->isEmpty();
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

                Section::make('Mode Setup Data Awal')
                    ->description('Pilih apakah sistem menyiapkan data master otomatis, atau Anda akan setup manual dari nol.')
                    ->schema([
                        Radio::make('seed_mode')
                            ->label('Cara Setup')
                            ->required()
                            ->default('template')
                            ->options([
                                'template' => 'Standar (Rekomendasi) — Auto-setup COA 51 akun + 5 lini bisnis + 7 material default',
                                'empty'    => 'Kosongan — Saya akan buat COA dan master data manual',
                            ])
                            ->descriptions([
                                'template' => 'Cocok untuk perusahaan alat berat / dump truck / kontraktor pengurugan. Struktur akun mengikuti standar akuntansi Indonesia. Langsung bisa pakai fitur transaksi.',
                                'empty'    => 'Cocok kalau perusahaan Anda punya struktur COA khusus. Perhatian: fitur transaksi (invoice, jurnal, penyusutan) BARU bisa dipakai setelah Anda buat minimal akun-akun ini di menu Master Data: 111100 Kas, 111200 Piutang, 221100 Utang, 441xxx Pendapatan, 551xxx Beban HPP, 552xxx Beban Operasional.',
                            ]),
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

        // Attach user yang sedang login sebagai owner.
        $company->users()->attach(auth()->id(), [
            'role'      => 'owner',
            'is_active' => true,
        ]);

        // Conditional seed berdasar pilihan user di form.
        // 'template' → auto-seed COA + BU + Material (default: rekomendasi).
        // 'empty'    → skip seed, user setup manual — beri notifikasi peringatan.
        $seedMode = $data['seed_mode'] ?? 'template';

        if ($seedMode === 'template') {
            app(CompanyTemplateService::class)->seedDefaults($company);

            Notification::make()
                ->title('PT tersedia dengan template lengkap')
                ->body('COA 51 akun, 5 lini bisnis, dan 7 material default telah di-set. Anda bisa langsung input transaksi.')
                ->success()
                ->duration(8000)
                ->send();
        } else {
            Notification::make()
                ->title('PT terbuat dalam mode kosongan')
                ->body('Silakan setup Chart of Accounts di Master Data → Daftar Akun sebelum mulai input transaksi. Minimal akun yang wajib ada: Kas, Piutang, Utang, Pendapatan, Beban HPP, Beban Operasional.')
                ->warning()
                ->duration(15000)
                ->persistent()
                ->send();
        }

        return $company;
    }
}
