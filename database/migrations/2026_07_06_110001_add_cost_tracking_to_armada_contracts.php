<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost tracking di armada_contracts (ARMD/dump truck) — pola sama dengan
     * rental_contracts, tapi basis unit-nya per RIT (trip), bukan per jam.
     *
     * tipe_kontrak:
     *   - all_in     : PT tanggung BBM + supir + uang jalan + uang makan
     *   - semi       : PT tanggung supir + makan; BBM dari klien
     *   - alat_saja  : klien tanggung semuanya (jarang untuk dump truck,
     *                  disediakan untuk konsistensi)
     *
     * Standar biaya per rit + per hari — dump truck biasanya push multiple rit
     * per hari. Gaji supir dan uang makan flat harian (kalau supir bekerja
     * hari itu, satu kali). Uang jalan dan premi per rit.
     *
     * BBM per rit = tergantung jarak route. bbm_liter_per_rit di-input estimasi
     * awal saat kontrak; log bisa override kalau actual berbeda.
     */
    public function up(): void
    {
        Schema::table('armada_contracts', function (Blueprint $table) {
            $table->enum('tipe_kontrak', ['all_in', 'semi', 'alat_saja'])
                ->default('all_in')
                ->after('client_id');

            $table->boolean('include_bbm')->default(true)->after('tipe_kontrak');
            $table->boolean('include_operator')->default(true)->after('include_bbm');

            $table->decimal('bbm_liter_per_rit', 10, 2)->nullable()->after('tarif_per_rit');
            $table->decimal('harga_bbm_per_liter', 20, 2)->nullable()->after('bbm_liter_per_rit');
            $table->decimal('gaji_supir_per_hari', 20, 2)->nullable()->after('harga_bbm_per_liter');
            $table->decimal('uang_makan_per_hari', 20, 2)->nullable()->after('gaji_supir_per_hari');
            $table->decimal('uang_jalan_per_rit', 20, 2)->nullable()->after('uang_makan_per_hari');
            $table->decimal('premi_per_rit', 20, 2)->nullable()->after('uang_jalan_per_rit');
        });
    }

    public function down(): void
    {
        Schema::table('armada_contracts', function (Blueprint $table) {
            $table->dropColumn([
                'tipe_kontrak',
                'include_bbm',
                'include_operator',
                'bbm_liter_per_rit',
                'harga_bbm_per_liter',
                'gaji_supir_per_hari',
                'uang_makan_per_hari',
                'uang_jalan_per_rit',
                'premi_per_rit',
            ]);
        });
    }
};
