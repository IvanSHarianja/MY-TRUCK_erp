<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost tracking di rental_contracts untuk skema HYBRID:
     * standar biaya di kontrak → auto-hitung di rental_logs.
     *
     * tipe_rental menentukan siapa yang tanggung apa:
     *   - all_in     : PT tanggung BBM + operator + uang makan
     *   - semi       : PT tanggung operator + uang makan; BBM dari klien
     *   - alat_saja  : klien tanggung semuanya (dry rental)
     *
     * include_bbm & include_operator adalah flag turunan yang gampang di-query
     * di service tanpa parsing string. Business rule tetap ada di service:
     *   - all_in     → include_bbm=true,  include_operator=true
     *   - semi       → include_bbm=false, include_operator=true
     *   - alat_saja  → include_bbm=false, include_operator=false
     *
     * Semua kolom biaya nullable karena hanya wajib bila include-flag aktif.
     * Log-log lama yang tersimpan dengan nilai kosong tetap valid (backward-compat).
     */
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->enum('tipe_rental', ['all_in', 'semi', 'alat_saja'])
                ->default('alat_saja')
                ->after('asset_id');

            $table->boolean('include_bbm')->default(false)->after('tipe_rental');
            $table->boolean('include_operator')->default(false)->after('include_bbm');

            $table->decimal('bbm_liter_per_jam', 10, 2)->nullable()->after('tarif_per_jam');
            $table->decimal('harga_bbm_per_liter', 20, 2)->nullable()->after('bbm_liter_per_jam');
            $table->decimal('gaji_operator_per_hari', 20, 2)->nullable()->after('harga_bbm_per_liter');
            $table->decimal('uang_makan_per_hari', 20, 2)->nullable()->after('gaji_operator_per_hari');
            $table->decimal('premi_per_jam', 20, 2)->nullable()->after('uang_makan_per_hari');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn([
                'tipe_rental',
                'include_bbm',
                'include_operator',
                'bbm_liter_per_jam',
                'harga_bbm_per_liter',
                'gaji_operator_per_hari',
                'uang_makan_per_hari',
                'premi_per_jam',
            ]);
        });
    }
};
