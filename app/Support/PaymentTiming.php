<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Satu definisi "telat bayar" untuk seluruh aplikasi (tabel Tagihan,
 * riwayat pembayaran klien, laporan piutang, export).
 *
 * Telat dihitung dari JATUH TEMPO (bukan tanggal invoice):
 *   - invoice lunas   → tanggal pelunasan (pembayaran terakhir) − jatuh tempo
 *   - belum lunas     → hari ini (atau tanggal laporan) − jatuh tempo
 *   - per pembayaran  → tanggal bayar − jatuh tempo
 * Hasil < 0 dianggap 0 (tepat waktu). Invoice tanpa jatuh tempo memakai
 * tanggal invoice (jatuh tempo seketika).
 */
final class PaymentTiming
{
    /** Kelompok umur piutang berdasarkan hari telat (laporan & filter). */
    public const BUCKETS = [
        'belum' => 'Belum jatuh tempo',
        '1-30'  => 'Telat 1–30 hari',
        '31-60' => 'Telat 31–60 hari',
        '61-90' => 'Telat 61–90 hari',
        '>90'   => 'Telat > 90 hari',
    ];

    public static function daysLate(?CarbonInterface $due, ?CarbonInterface $at): int
    {
        if (! $due || ! $at) {
            return 0;
        }

        return max(0, (int) Carbon::parse($due)->startOfDay()->diffInDays(Carbon::parse($at)->startOfDay(), false));
    }

    public static function bucket(int $daysLate): string
    {
        return match (true) {
            $daysLate <= 0  => 'belum',
            $daysLate <= 30 => '1-30',
            $daysLate <= 60 => '31-60',
            $daysLate <= 90 => '61-90',
            default         => '>90',
        };
    }

    /** Label pembayaran tunggal, mis. di riwayat pembayaran klien. */
    public static function paymentLabel(int $daysLate): string
    {
        return $daysLate > 0 ? "Telat {$daysLate} hari" : 'Tepat waktu';
    }
}
