<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lebarkan accounts.code & parent_code dari 10 → 30 karakter.
 *
 * MASALAH:
 *   Sub-akun memakai pola {kode induk}-NN (Account::suggestChildCode).
 *   Satu tingkat masih muat: 111100 → 111100-01 (9 karakter).
 *   Dua tingkat melebihi kolom: 111100-01 → 111100-01-01 (12 karakter)
 *   → SQLSTATE[22001] "Data too long for column 'code'" saat simpan.
 *
 *   Form COA juga mengizinkan 20 karakter (maxLength) padahal kolom hanya 10,
 *   jadi kode custom yang panjang gagal dengan error SQL mentah, bukan pesan
 *   validasi yang ramah.
 *
 * KENAPA 30:
 *   Kode standar 6 digit + 8 tingkat sub-akun (-NN) masih muat. Index unique
 *   (company_id, code) tetap jauh di bawah batas 3072 byte MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->string('code', 30)->change();
            $t->string('parent_code', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rollback memotong data — tolak bila ada kode yang sudah melebihi 10
        // karakter, daripada diam-diam merusak COA.
        $terlaluPanjang = DB::table('accounts')
            ->whereRaw('LENGTH(code) > 10')
            ->orWhereRaw('LENGTH(parent_code) > 10')
            ->count();

        if ($terlaluPanjang > 0) {
            throw new RuntimeException(
                "Rollback dibatalkan: ada {$terlaluPanjang} akun dengan kode lebih dari 10 karakter. "
                . 'Persingkat kodenya dulu, atau biarkan migrasi ini tetap ter-apply.'
            );
        }

        Schema::table('accounts', function (Blueprint $t) {
            $t->string('code', 10)->change();
            $t->string('parent_code', 10)->nullable()->change();
        });
    }
};
