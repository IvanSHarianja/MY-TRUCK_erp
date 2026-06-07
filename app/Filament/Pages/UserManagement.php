<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagement extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.user-management';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $title = 'Manajemen Pengguna PT';

    /**
     * Sembunyikan dari sidebar — akses via user dropdown menu (kanan atas).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Akses halaman hanya untuk owner / admin di PT aktif.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return false;
        }

        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $pivot = $user->companies()
            ->where('companies.id', $tenant->getKey())
            ->first()
            ?->pivot;

        return $pivot && in_array($pivot->role, ['owner', 'admin']);
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->query(
                User::query()
                    ->whereHas('companies', fn (Builder $q) => $q->where('companies.id', $tenant->getKey()))
                    ->with(['companies' => fn ($q) => $q->where('companies.id', $tenant->getKey())])
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label('Role')
                    ->state(fn (User $record): string => $record->companies->first()?->pivot->role ?? '–')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'owner'      => 'success',
                        'admin'      => 'info',
                        'accountant' => 'warning',
                        'viewer'     => 'gray',
                        default      => 'gray',
                    }),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->state(fn (User $record): bool => (bool) ($record->companies->first()?->pivot->is_active ?? false))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Bergabung')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label('Tambah Pengguna')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('primary')
                    ->modalHeading('Beri Akses ke PT Ini')
                    ->modalDescription('Cari user yang sudah ada via email, atau buat user baru.')
                    ->modalSubmitActionLabel('Beri Akses')
                    ->schema([
                        Select::make('mode')
                            ->label('Tipe Pengguna')
                            ->options([
                                'existing' => 'User Sudah Ada (cari via email)',
                                'new'      => 'Buat User Baru',
                            ])
                            ->default('existing')
                            ->required()
                            ->live()
                            ->native(false),

                        // === Mode: Existing user ===
                        Select::make('existing_user_id')
                            ->label('Cari User')
                            ->placeholder('Ketik email atau nama...')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) use ($tenant) {
                                return User::query()
                                    ->where(fn ($q) => $q->where('email', 'like', "%{$search}%")
                                        ->orWhere('name', 'like', "%{$search}%"))
                                    ->whereDoesntHave('companies', fn ($q) => $q->where('companies.id', $tenant->getKey()))
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->email})"])
                                    ->toArray();
                            })
                            ->visible(fn ($get) => $get('mode') === 'existing')
                            ->requiredIf('mode', 'existing'),

                        // === Mode: New user ===
                        TextInput::make('new_name')
                            ->label('Nama Lengkap')
                            ->placeholder('Bapak / Ibu ...')
                            ->maxLength(255)
                            ->visible(fn ($get) => $get('mode') === 'new')
                            ->requiredIf('mode', 'new'),

                        TextInput::make('new_email')
                            ->label('Email')
                            ->email()
                            ->placeholder('staff@perusahaan.com')
                            ->visible(fn ($get) => $get('mode') === 'new')
                            ->requiredIf('mode', 'new')
                            ->rule(Rule::unique('users', 'email')),

                        TextInput::make('new_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->minLength(6)
                            ->placeholder('Minimal 6 karakter')
                            ->visible(fn ($get) => $get('mode') === 'new')
                            ->requiredIf('mode', 'new')
                            ->helperText('Bagikan password ini ke user via cara aman setelah dibuat.'),

                        // === Role (selalu tampil) ===
                        Select::make('role')
                            ->label('Role di PT Ini')
                            ->options([
                                'owner'      => 'Owner — akses penuh termasuk hapus PT',
                                'admin'      => 'Admin — CRUD jurnal & data, kelola user',
                                'accountant' => 'Accountant — input jurnal & laporan, no manage user',
                                'viewer'     => 'Viewer — hanya lihat laporan',
                            ])
                            ->default('accountant')
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data) use ($tenant) {
                        if ($data['mode'] === 'new') {
                            $user = User::create([
                                'name'     => $data['new_name'],
                                'email'    => $data['new_email'],
                                'password' => Hash::make($data['new_password']),
                            ]);
                        } else {
                            $user = User::findOrFail($data['existing_user_id']);
                        }

                        // Cek apakah sudah attached (safety)
                        $alreadyAttached = $tenant->users()->where('users.id', $user->id)->exists();
                        if ($alreadyAttached) {
                            Notification::make()
                                ->title('User sudah punya akses')
                                ->body("{$user->email} sudah ada di PT ini.")
                                ->warning()
                                ->send();
                            return;
                        }

                        $tenant->users()->attach($user->id, [
                            'role'      => $data['role'],
                            'is_active' => true,
                        ]);

                        Notification::make()
                            ->title('Akses berhasil diberikan')
                            ->body("{$user->name} ({$user->email}) sekarang bisa akses PT ini sebagai {$data['role']}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('editRole')
                    ->label('Ubah Role')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Ubah Role Pengguna')
                    ->fillForm(fn (User $record) => [
                        'role'      => $record->companies->first()?->pivot->role,
                        'is_active' => (bool) ($record->companies->first()?->pivot->is_active ?? true),
                    ])
                    ->schema([
                        Select::make('role')
                            ->label('Role')
                            ->options([
                                'owner'      => 'Owner',
                                'admin'      => 'Admin',
                                'accountant' => 'Accountant',
                                'viewer'     => 'Viewer',
                            ])
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Nonaktifkan untuk suspend akses sementara tanpa hapus.'),
                    ])
                    ->action(function (User $record, array $data) use ($tenant) {
                        // Cegah owner terakhir di-demote
                        $newRole = $data['role'];
                        $currentRole = $record->companies->first()?->pivot->role;

                        if ($currentRole === 'owner' && $newRole !== 'owner') {
                            $ownerCount = $tenant->users()->wherePivot('role', 'owner')->count();
                            if ($ownerCount <= 1) {
                                Notification::make()
                                    ->title('Tidak bisa demote owner terakhir')
                                    ->body('PT harus punya minimal 1 owner. Promote user lain ke owner dulu.')
                                    ->danger()
                                    ->send();
                                return;
                            }
                        }

                        $tenant->users()->updateExistingPivot($record->id, [
                            'role'      => $data['role'],
                            'is_active' => $data['is_active'],
                        ]);

                        Notification::make()
                            ->title('Role diperbarui')
                            ->body("{$record->name} sekarang sebagai {$data['role']}.")
                            ->success()
                            ->send();
                    }),

                Action::make('revoke')
                    ->label('Cabut Akses')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cabut Akses User dari PT Ini?')
                    ->modalDescription(fn (User $record) => "User {$record->name} tidak akan bisa akses PT ini lagi. Akun user-nya TIDAK terhapus, hanya dilepas dari PT.")
                    ->visible(fn (User $record) => $record->id !== auth()->id())
                    ->action(function (User $record) use ($tenant) {
                        $currentRole = $record->companies->first()?->pivot->role;

                        if ($currentRole === 'owner') {
                            $ownerCount = $tenant->users()->wherePivot('role', 'owner')->count();
                            if ($ownerCount <= 1) {
                                Notification::make()
                                    ->title('Tidak bisa cabut owner terakhir')
                                    ->body('PT harus punya minimal 1 owner.')
                                    ->danger()
                                    ->send();
                                return;
                            }
                        }

                        $tenant->users()->detach($record->id);

                        Notification::make()
                            ->title('Akses dicabut')
                            ->body("{$record->name} sudah tidak punya akses ke PT ini.")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                // (kosong — bulk action tidak relevan di sini)
            ])
            ->emptyStateHeading('Belum ada pengguna lain')
            ->emptyStateDescription('Klik "Tambah Pengguna" untuk invite user lain ke PT ini.')
            ->emptyStateIcon(Heroicon::OutlinedUsers);
    }
}
