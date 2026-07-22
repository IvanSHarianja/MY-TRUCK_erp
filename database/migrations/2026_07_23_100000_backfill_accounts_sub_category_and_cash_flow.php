<?php

use App\Enums\AccountRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill sub_category & cash_flow_category untuk akun existing yang NULL.
 *
 * TUJUAN:
 * Setelah patch auto-inherit di Account::booted() saving event, akun BARU
 * otomatis dapat sub_category & cash_flow_category. TAPI akun EXISTING
 * yang sudah dibuat sebelum patch mungkin punya field NULL — bikin
 * filter Neraca (sub_category) dan Arus Kas (cash_flow_category) gagal.
 *
 * Migrasi ini bersihkan data legacy sekali: untuk semua akun dengan
 * field NULL, isi dari role (kalau ada) atau default per category.
 *
 * SAFE: hanya sentuh akun yang field-nya NULL. Data existing yang sudah
 * diisi user manual tidak diubah.
 *
 * Idempotent: bisa di-jalankan berulang tanpa efek samping.
 */
return new class extends Migration
{
    public function up(): void
    {
        // === Phase 1: backfill dari role (prioritas 1) ===
        // Untuk setiap role, update akun dengan role tsb yang sub_category NULL
        foreach (AccountRole::cases() as $role) {
            $subCategory = $role->defaultSubCategory();
            $cashFlow    = $role->defaultCashFlow();

            // Backfill sub_category dari role
            DB::table('accounts')
                ->where('role', $role->value)
                ->whereNull('sub_category')
                ->update(['sub_category' => $subCategory, 'updated_at' => now()]);

            // Backfill cash_flow_category dari role
            DB::table('accounts')
                ->where('role', $role->value)
                ->whereNull('cash_flow_category')
                ->update(['cash_flow_category' => $cashFlow, 'updated_at' => now()]);
        }

        // === Phase 2: backfill dari category (prioritas 2 — sisanya) ===
        $categoryDefaults = [
            'aset'       => ['sub' => 'aset_lancar',        'cf' => 'operasi'],
            'kewajiban'  => ['sub' => 'kewajiban_lancar',   'cf' => 'operasi'],
            'ekuitas'    => ['sub' => 'ekuitas',            'cf' => 'pendanaan'],
            'pendapatan' => ['sub' => 'pendapatan_usaha',   'cf' => 'operasi'],
            'beban'      => ['sub' => 'beban_operasional',  'cf' => 'operasi'],
            'penutup'    => ['sub' => 'penutup',            'cf' => 'non_kas'],
        ];

        foreach ($categoryDefaults as $category => $defaults) {
            DB::table('accounts')
                ->where('category', $category)
                ->whereNull('sub_category')
                ->update(['sub_category' => $defaults['sub'], 'updated_at' => now()]);

            DB::table('accounts')
                ->where('category', $category)
                ->whereNull('cash_flow_category')
                ->update(['cash_flow_category' => $defaults['cf'], 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // No-op: data backfill tidak reversible (tidak tahu mana yang originally NULL).
        // Kalau perlu revert, restore dari backup.
    }
};
