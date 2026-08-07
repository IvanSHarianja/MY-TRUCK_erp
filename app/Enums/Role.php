<?php

namespace App\Enums;

/**
 * Role user PER-TENANT di MY-TRUCK.
 *
 * Disimpan di pivot `company_user.role`. Satu user boleh punya role berbeda
 * di PT berbeda. Cek akses lewat User::canIn(tenant, Permission) atau
 * User::roleIn(tenant).
 *
 * Untuk menambah/mengubah izin per role, edit App\Support\RoleMatrix
 * (single source of truth) — jangan hard-code di Policy/Page.
 */
enum Role: string
{
    case Owner      = 'owner';
    case Admin      = 'admin';
    case Accountant = 'accountant';
    case Viewer     = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner      => 'Owner',
            self::Admin      => 'Admin',
            self::Accountant => 'Accountant',
            self::Viewer     => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner      => 'Akses penuh — kelola PT, close period, hapus PT.',
            self::Admin      => 'CRUD data & void — kelola user, master, void jurnal.',
            self::Accountant => 'Input jurnal & operasional — tidak boleh void atau kelola master.',
            self::Viewer     => 'Read-only laporan & export PDF.',
        };
    }

    /**
     * Warna badge Filament untuk display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Owner      => 'success',
            self::Admin      => 'info',
            self::Accountant => 'warning',
            self::Viewer     => 'gray',
        };
    }

    /**
     * @return array<string, string>  ['owner' => 'Owner', …]
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }

    /**
     * Options lengkap dengan deskripsi — untuk dropdown Assign Role.
     * @return array<string, string>
     */
    public static function optionsWithDescription(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label() . ' — ' . $case->description();
        }
        return $out;
    }
}
