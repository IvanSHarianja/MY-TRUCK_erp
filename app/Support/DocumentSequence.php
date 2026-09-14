<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Nomor dokumen berurutan per prefix: {PREFIX}{NNNN}, contoh J2609-001, INV2609-0001.
 *
 * Kenapa helper ini ada: sebelumnya tiap service mengambil nomor terakhir via
 * ORDER BY kolom DESC (urutan string) lalu substr(-N). Urutan string menganggap
 * "J2609-999" > "J2609-1000", jadi setelah nomor ke-1000 generator terus
 * mengeluarkan nomor yang sudah dipakai → unique violation → gagal permanen.
 *
 * Urut LENGTH dulu baru nilai = urutan numerik untuk suffix berdigit, dan
 * portable di MySQL maupun SQLite (test). Padding tetap dipakai untuk nomor
 * kecil (backward compatible), lalu tumbuh alami: 999 → 1000 → 1001.
 *
 * Tidak race-safe dengan sendirinya — caller tetap mengandalkan unique index
 * (company_id, kolom) + retry seperti sebelumnya.
 */
final class DocumentSequence
{
    /**
     * @param  Builder  $query      Query yang sudah di-scope (mis. per company_id)
     * @param  string   $column     Kolom nomor dokumen
     * @param  string   $prefix     Prefix lengkap termasuk pemisah, mis. "J2609-"
     * @param  int      $padLength  Minimal digit suffix
     */
    public static function next(Builder $query, string $column, string $prefix, int $padLength): string
    {
        $last = (clone $query)
            ->where($column, 'like', $prefix . '%')
            ->orderByRaw("LENGTH({$column}) DESC")
            ->orderByDesc($column)
            ->value($column);

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, $padLength, '0', STR_PAD_LEFT);
    }
}
