<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\AccountingPeriod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class EditCompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Profil Perusahaan';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Perusahaan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Perusahaan')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('slug')
                            ->label('Slug (URL)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Slug tidak bisa diubah setelah PT terdaftar.'),

                        TextInput::make('owner_name')
                            ->label('Nama Pimpinan')
                            ->placeholder('Digunakan untuk tanda tangan laporan')
                            ->maxLength(255),

                        Select::make('fiscal_year')
                            ->label('Tahun Buku')
                            ->options(collect(range(now()->year - 5, now()->year + 1))
                                ->mapWithKeys(fn ($y) => [$y => (string) $y]))
                            ->required()
                            ->native(false),

                        DatePicker::make('fiscal_start')
                            ->label('Awal Periode Buku')
                            ->required()
                            ->native(false),

                        DatePicker::make('fiscal_end')
                            ->label('Akhir Periode Buku')
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),

                Section::make('Kontak')
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone')->label('Telepon')->tel()->maxLength(30),
                        TextInput::make('email')->label('Email')->email()->maxLength(255),
                        Textarea::make('address')->label('Alamat Lengkap')->rows(3)->columnSpanFull(),
                    ]),

                Section::make('Setting Operasional')
                    ->description('Nilai default yang dipakai sistem untuk auto-hitung biaya operasional. '
                        . 'Setiap kontrak Rental/Armada bisa override nilai ini di level kontrak.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('harga_solar_default')
                            ->label('Harga Solar Default (Rp/liter)')
                            ->numeric()
                            ->prefix('Rp')
                            ->suffix('/ liter')
                            ->default(6800)
                            ->required()
                            ->minValue(1)
                            ->helperText('Dipakai auto-hitung Beban BBM di RentalLog & RitLog. '
                                . 'Kalau harga solar naik/turun, update di sini agar semua kontrak '
                                . 'yang tidak override langsung ikut nilai baru. Log yang sudah terjurnal '
                                . 'sebelumnya tidak terpengaruh (immutable).'),
                    ]),

                Section::make('Logo & Branding')
                    ->schema([
                        FileUpload::make('logo')
                            ->label('Logo Perusahaan')
                            ->image()
                            ->directory('company-logos')
                            ->maxSize(2048)
                            ->helperText('Format: PNG/JPG. Maksimal 2 MB.'),
                    ])
                    ->collapsed(),

                Section::make('🛑 Zona Berbahaya')
                    ->description('Aksi di sini bersifat permanen. Pastikan Anda sudah backup data sebelum melanjutkan.')
                    ->collapsed()
                    ->schema([
                        Section::make('Informasi Data Saat Ini')
                            ->schema([
                                \Filament\Forms\Components\Placeholder::make('stats')
                                    ->label('')
                                    ->content(function (): string {
                                        $tenant   = Filament::getTenant();
                                        $jurnal   = JournalEntry::where('company_id', $tenant->id)->count();
                                        $periods  = AccountingPeriod::where('company_id', $tenant->id)->count();

                                        return sprintf(
                                            'PT ini memiliki: %d jurnal dan %d periode akuntansi tercatat.',
                                            $jurnal,
                                            $periods,
                                        );
                                    }),
                            ])
                            ->compact(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // === Aksi: Reset Semua Jurnal ===
            Action::make('resetJournals')
                ->label('Hapus Semua Jurnal')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Hapus Semua Jurnal di PT Ini?')
                ->modalDescription('Akan menghapus SEMUA jurnal (draft + posted + void) dan periode akuntansi PT ini. Master data (COA, lini bisnis, klien, vendor, aset, karyawan) TIDAK terhapus. Tindakan ini tidak bisa dibatalkan.')
                ->modalSubmitActionLabel('Ya, hapus semua jurnal')
                ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                ->schema([
                    TextInput::make('confirmation')
                        ->label('Ketik HAPUS JURNAL untuk konfirmasi')
                        ->required()
                        ->rule('in:HAPUS JURNAL')
                        ->validationMessages(['in' => 'Konfirmasi harus persis "HAPUS JURNAL"']),
                ])
                ->action(function () {
                    $tenant = Filament::getTenant();

                    DB::transaction(function () use ($tenant) {
                        $journalIds = JournalEntry::where('company_id', $tenant->id)->pluck('id');
                        $linesDeleted = JournalEntryLine::whereIn('journal_entry_id', $journalIds)->delete();
                        $journalDeleted = JournalEntry::where('company_id', $tenant->id)->delete();
                        $periodsDeleted = AccountingPeriod::where('company_id', $tenant->id)->delete();
                    });

                    Notification::make()
                        ->title('Semua jurnal & periode berhasil dihapus')
                        ->body('Master data (COA, lini bisnis, dll) tetap utuh. PT siap untuk input ulang dari nol.')
                        ->success()
                        ->send();
                }),

            // === Aksi: Hapus PT Selamanya ===
            Action::make('deleteCompany')
                ->label('Hapus PT Ini')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Hapus PT Ini Selamanya?')
                ->modalDescription('Semua data PT akan terhapus permanen (cascade): jurnal, COA, lini bisnis, klien, vendor, aset, karyawan, periode akuntansi. Tindakan ini TIDAK bisa dibatalkan.')
                ->modalSubmitActionLabel('Ya, hapus PT selamanya')
                ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                ->schema([
                    TextInput::make('confirmation')
                        ->label(fn () => 'Ketik nama PT untuk konfirmasi: ' . Filament::getTenant()->name)
                        ->required()
                        ->rule(fn () => 'in:' . Filament::getTenant()->name)
                        ->validationMessages(['in' => 'Nama PT tidak sesuai. Ketik persis nama PT.']),
                ])
                ->action(function () {
                    $tenant     = Filament::getTenant();
                    $tenantId   = $tenant->id;
                    $tenantName = $tenant->name;

                    // Cari PT lain yang user punya akses, untuk redirect setelah delete
                    $user = auth()->user();
                    $otherTenant = $user->companies()
                        ->where('companies.id', '!=', $tenantId)
                        ->wherePivot('is_active', true)
                        ->first();

                    // Manual ordered delete — TIDAK pakai cascade MySQL karena urutan tidak terjamin.
                    // FK `journal_entry_lines.account_id` ke `accounts` adalah RESTRICT (safeguard),
                    // jadi kita harus hapus child dulu sebelum parent.
                    DB::transaction(function () use ($tenantId) {
                        // 1. Hapus journal lines (depends on accounts via RESTRICT)
                        $journalIds = JournalEntry::where('company_id', $tenantId)->pluck('id');
                        JournalEntryLine::whereIn('journal_entry_id', $journalIds)->delete();

                        // 2. Hapus journal entries
                        JournalEntry::where('company_id', $tenantId)->delete();

                        // 3. Hapus accounting periods
                        AccountingPeriod::where('company_id', $tenantId)->delete();

                        // 4. Hapus employees (refs assets via nullOnDelete; safe but explicit)
                        Employee::where('company_id', $tenantId)->delete();

                        // 5. Hapus assets (refs accounts via nullOnDelete; safe but explicit)
                        Asset::where('company_id', $tenantId)->delete();

                        // 6. Hapus master data lain (depends only on company)
                        Client::where('company_id', $tenantId)->delete();
                        Vendor::where('company_id', $tenantId)->delete();

                        // 7. Hapus accounts & business_units
                        Account::where('company_id', $tenantId)->delete();
                        BusinessUnit::where('company_id', $tenantId)->delete();

                        // 8. Pivot company_user akan cascade saat company di-delete (kita biarkan)

                        // 9. Hapus company terakhir
                        Company::where('id', $tenantId)->delete();
                    });

                    Notification::make()
                        ->title("PT {$tenantName} berhasil dihapus")
                        ->body('Semua data terkait sudah terhapus permanen.')
                        ->success()
                        ->send();

                    if ($otherTenant) {
                        return redirect(Filament::getPanel('admin')->getUrl($otherTenant));
                    }

                    return redirect(Filament::getPanel('admin')->getTenantRegistrationUrl());
                }),
        ];
    }
}
