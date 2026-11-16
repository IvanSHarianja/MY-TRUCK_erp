<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sinkronkan include_bbm / include_operator dengan tipe kontrak.
 *
 * Form kontrak dulu mengubah flag ini lewat $set() tanpa field, jadi tidak
 * pernah tersimpan → kontrak bisa bertipe Alat Saja tapi flag = 1 (KA26-0002).
 * Mulai sekarang model menurunkannya dari tipe saat disimpan; migrasi ini
 * membetulkan data lama. Perhitungan biaya tidak terdampak (selalu pakai
 * tipe), hanya tampilan form.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['armada_contracts' => 'tipe_kontrak', 'rental_contracts' => 'tipe_rental'] as $table => $tipe) {
            DB::table($table)->update([
                'include_bbm'      => DB::raw("CASE WHEN {$tipe} = 'all_in' THEN 1 ELSE 0 END"),
                'include_operator' => DB::raw("CASE WHEN {$tipe} IN ('all_in', 'semi') THEN 1 ELSE 0 END"),
            ]);
        }
    }

    public function down(): void
    {
        // Tidak dibalik: nilai lama memang salah.
    }
};
