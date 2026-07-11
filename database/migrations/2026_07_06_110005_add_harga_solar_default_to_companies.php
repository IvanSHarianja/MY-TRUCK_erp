<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Setting harga solar per company — dipakai sebagai default di seluruh
     * perhitungan auto-post BBM saat harga_bbm_per_liter belum di-set di
     * contract level.
     *
     * Business decision A2 disetujui 2026-07-06: setting global per company,
     * 1 nilai, editable. Upgrade ke harga per periode bulanan (opsi B) bila
     * volatile harga solar jadi masalah operasional.
     *
     * Default 6800 (harga solar subsidi rata-rata 2026 di Indonesia). User
     * bisa update di halaman profil company.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('harga_solar_default', 20, 2)
                ->default(6800)
                ->after('fiscal_end');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('harga_solar_default');
        });
    }
};
