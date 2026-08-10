<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\Role;
use App\Support\RoleAccessManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Matriks akses per role — interactive untuk Owner (toggle checkbox
 * langsung save), read-only untuk role lain.
 *
 * Owner column selalu semua ✓ dan tidak bisa di-toggle (safety: cegah
 * owner disable dirinya sendiri).
 */
class RoleMatrix extends Page
{
    protected string $view = 'filament.pages.role-matrix';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Role Akses';

    // Tanpa group + sort tinggi → tampil paling bawah, di luar "Laporan Keuangan"
    // dan "Master Data".
    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 999;

    protected static ?string $title = 'Role Akses';

    public static function canAccess(): bool
    {
        // Hanya Owner yang boleh akses halaman Role Akses (baik view maupun edit).
        // Non-owner: menu hidden dari sidebar + 403 kalau akses URL langsung.
        return auth()->user()?->canCurrent(Permission::RoleManage) ?? false;
    }

    /**
     * Apakah user boleh EDIT matrix? Hanya owner.
     */
    public function canEdit(): bool
    {
        return auth()->user()?->canCurrent(Permission::RoleManage) ?? false;
    }

    /**
     * Toggle satu cell matrix. Dipanggil dari Blade via wire:click.
     *
     * @param  string $roleValue        'admin' | 'accountant' | 'viewer'
     * @param  string $permissionValue  Permission enum value
     * @param  bool   $newValue         Nilai baru
     */
    public function togglePermission(string $roleValue, string $permissionValue, bool $newValue): void
    {
        if (! $this->canEdit()) {
            Notification::make()
                ->title('Tidak diizinkan')
                ->body('Hanya Owner yang boleh mengubah matrix akses.')
                ->danger()
                ->send();
            return;
        }

        $role       = Role::tryFrom($roleValue);
        $permission = Permission::tryFrom($permissionValue);

        if (! $role || ! $permission) {
            Notification::make()->title('Input invalid')->danger()->send();
            return;
        }

        if ($role === Role::Owner) {
            Notification::make()
                ->title('Owner tidak bisa diubah')
                ->body('Permission Owner immutable — selalu akses penuh.')
                ->warning()
                ->send();
            return;
        }

        $tenant = Filament::getTenant();

        try {
            app(RoleAccessManager::class)->set(
                $tenant,
                $role,
                $permission,
                $newValue,
                auth()->user(),
            );

            Notification::make()
                ->title($newValue ? 'Diberi akses' : 'Akses dicabut')
                ->body($role->label() . ' — ' . $permission->label())
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal menyimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetDefaults')
                ->label('Reset ke Default')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn () => $this->canEdit())
                ->requiresConfirmation()
                ->modalHeading('Reset Matriks Akses ke Default?')
                ->modalDescription('Semua override kustom untuk PT ini akan dihapus. Matrix kembali ke default sistem. Aksi ini tidak bisa dibatalkan.')
                ->modalSubmitActionLabel('Ya, reset ke default')
                ->action(function () {
                    $tenant = Filament::getTenant();
                    $deleted = app(RoleAccessManager::class)->reset($tenant);
                    Notification::make()
                        ->title('Matriks direset ke default')
                        ->body("{$deleted} override kustom dihapus.")
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        return [
            'roles'    => Role::cases(),
            'grouped'  => app(RoleAccessManager::class)->matrixGroupedFor($tenant),
            'canEdit'  => $this->canEdit(),
        ];
    }
}
