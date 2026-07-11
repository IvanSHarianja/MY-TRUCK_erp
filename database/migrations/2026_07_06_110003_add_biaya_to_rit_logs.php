<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Biaya operasional actual di rit_logs (ARMD harian per trip log).
     *
     * Berbeda dari rental_logs, rit_logs sebelumnya belum punya solar_liter.
     * Ini juga menambahkan itu.
     *
     * Basis perhitungan (kalau override_biaya=false):
     *   - BBM        = rit_count × contract.bbm_liter_per_rit × harga_solar
     *   - Gaji supir = 1 hari × contract.gaji_supir_per_hari
     *   - Uang jalan = rit_count × contract.uang_jalan_per_rit
     *   - Uang makan = 1 hari × contract.uang_makan_per_hari
     *   - Premi      = rit_count × contract.premi_per_rit
     *
     * Beda dengan rental: gaji supir & uang makan flat per hari kerja (kalau
     * supir kerja hari itu), sisanya scaling by rit_count.
     */
    public function up(): void
    {
        Schema::table('rit_logs', function (Blueprint $table) {
            $table->decimal('solar_liter', 10, 2)->nullable()->after('rit_count');
            $table->decimal('uang_jalan_supir', 20, 2)->nullable()->after('solar_liter');
            $table->decimal('uang_makan_supir', 20, 2)->nullable()->after('uang_jalan_supir');
            $table->decimal('premi_supir', 20, 2)->nullable()->after('uang_makan_supir');
            $table->boolean('override_biaya')->default(false)->after('premi_supir');
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->after('override_biaya')
                ->constrained('journal_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn([
                'solar_liter',
                'uang_jalan_supir',
                'uang_makan_supir',
                'premi_supir',
                'override_biaya',
            ]);
        });
    }
};
