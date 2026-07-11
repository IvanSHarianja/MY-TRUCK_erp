<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Biaya operasional actual di rental_logs (RENT harian).
     *
     * rental_logs sudah punya solar_liter & voucher_solar (data operasional).
     * Ini menambahkan komponen biaya yang belum tercatat, plus mekanisme
     * override & link ke jurnal auto.
     *
     * override_biaya = false (default):
     *   Service auto-hitung biaya dari standar contract × jam_kerja:
     *     - BBM        = jam_kerja × contract.bbm_liter_per_jam × harga_solar
     *     - Gaji       = 1 hari × contract.gaji_operator_per_hari
     *     - Uang makan = 1 hari × contract.uang_makan_per_hari
     *     - Premi      = jam_kerja × contract.premi_per_jam
     *
     * override_biaya = true:
     *   User isi manual di form (misal hari lembur solar boros, premi khusus).
     *   Service pakai nilai yang tersimpan di kolom ini.
     *
     * journal_entry_id link balik ke jurnal auto-generated supaya:
     *   - Bisa cek idempotency (log yang sudah punya jurnal tidak dobel-post).
     *   - Kalau log dihapus, jurnal terkait bisa di-void otomatis.
     */
    public function up(): void
    {
        Schema::table('rental_logs', function (Blueprint $table) {
            $table->decimal('uang_makan_operator', 20, 2)->nullable()->after('voucher_solar');
            $table->decimal('premi_operator', 20, 2)->nullable()->after('uang_makan_operator');
            $table->boolean('override_biaya')->default(false)->after('premi_operator');
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->after('override_biaya')
                ->constrained('journal_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn([
                'uang_makan_operator',
                'premi_operator',
                'override_biaya',
            ]);
        });
    }
};
