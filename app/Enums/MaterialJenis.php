<?php

namespace App\Enums;

/**
 * Jenis master material — memisahkan barang dagangan dari sparepart.
 *
 * KENAPA SATU TABEL, BUKAN MODUL TERPISAH:
 * Sparepart butuh persis mesin yang sudah dipakai material dagangan — stok,
 * Moving Average Cost, riwayat pergerakan, dan rollback stok saat void. Membuat
 * modul sendiri berarti menduplikasi semuanya beserta bug-nya.
 *
 * BEDA PERLAKUAN:
 *   Jual      → dijual ke klien lewat Penjualan Material (Dr Kas/Piutang, Cr Pendapatan)
 *               + HPP (Dr HPP Material, Cr Persediaan Material 111260)
 *   Sparepart → dipakai sendiri saat servis unit (Dr Beban Maintenance,
 *               Cr Persediaan Sparepart 111270). Tidak muncul di form penjualan.
 */
enum MaterialJenis: string
{
    case Jual      = 'jual';
    case Sparepart = 'sparepart';

    public function label(): string
    {
        return match ($this) {
            self::Jual      => 'Dijual ke Klien',
            self::Sparepart => 'Sparepart (Dipakai Sendiri)',
        };
    }

    /** Peran akun persediaan yang dipakai jenis ini. */
    public function inventoryRole(): AccountRole
    {
        return match ($this) {
            self::Jual      => AccountRole::InventoryMaterial,
            self::Sparepart => AccountRole::InventorySparepart,
        };
    }

    /** Kode akun persediaan standar (fallback bila role belum di-set). */
    public function inventoryCode(): string
    {
        return match ($this) {
            self::Jual      => '111260',
            self::Sparepart => '111270',
        };
    }

    /** @return array<string, string> untuk Filament Select options */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
