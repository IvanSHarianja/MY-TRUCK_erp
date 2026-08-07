<?php

namespace App\Enums;

/**
 * BIZ-02: metode depresiasi per aset.
 *
 * - StraightLine  : garis lurus bulanan (default & backward-compatible).
 *                    Dipicu bulanan oleh RunDepreciationCommand.
 * - PerHour       : units-of-production per jam kerja (PSAK 16).
 *                    Dipicu saat RentalLog di-post (via observer, BIZ-03).
 * - PerRit        : per rit muatan (dump truck operasi tonase).
 *                    Dipicu saat RitLog di-post (BIZ-03).
 * - PerDay        : per hari kerja (sewa harian all-in).
 *                    Dipicu saat log berbasis-hari di-post (BIZ-03).
 *
 * Aturan penting:
 *  - Method dikunci setelah aset punya journal DEP-* / DEPUSE-* pertama
 *    (lihat Asset::booted). Ubah method setelah jurnal ada = risiko
 *    double-depreciation di periode transisi.
 *  - Cron bulanan (RunDepreciationCommand) HANYA memproses StraightLine.
 *    Usage-based di-skip; jurnalnya datang dari log observer.
 */
enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case PerHour      = 'per_hour';
    case PerRit       = 'per_rit';
    case PerDay       = 'per_day';

    public function label(): string
    {
        return match ($this) {
            self::StraightLine => 'Garis Lurus (Bulanan)',
            self::PerHour      => 'Per Jam Kerja',
            self::PerRit       => 'Per Rit',
            self::PerDay       => 'Per Hari Kerja',
        };
    }

    /**
     * Nama kolom umur ekonomis di tabel `assets` yang dipakai oleh method ini.
     * StraightLine pakai kolom lama `useful_life_months`.
     */
    public function usefulLifeField(): string
    {
        return match ($this) {
            self::StraightLine => 'useful_life_months',
            self::PerHour      => 'useful_life_hours',
            self::PerRit       => 'useful_life_rits',
            self::PerDay       => 'useful_life_days',
        };
    }

    /**
     * Satuan display untuk UI (suffix input & label kolom laporan).
     */
    public function unitLabel(): string
    {
        return match ($this) {
            self::StraightLine => 'bulan',
            self::PerHour      => 'jam',
            self::PerRit       => 'rit',
            self::PerDay       => 'hari',
        };
    }

    public function isUsageBased(): bool
    {
        return $this !== self::StraightLine;
    }

    /**
     * Untuk dropdown Filament: ['value' => 'label'].
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
