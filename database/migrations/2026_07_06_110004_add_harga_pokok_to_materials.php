<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Harga pokok per master material — dipakai untuk auto-post HPP saat
     * penjualan material (Tahap 5: MaterialSaleService::postCogs()).
     *
     * Model simple (MVP): harga pokok fix per master. Untuk moving-average
     * atau FIFO lot-based, upgrade nanti dengan tabel material_stock_movements
     * — bukan sekarang. Business decision C1 disetujui 2026-07-06.
     *
     * Default 0 untuk backward compat: material yang belum di-set HPP akan
     * post HPP = 0 sampai user isi. Service akan warning tapi tidak throw.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('harga_pokok', 20, 2)
                ->default(0)
                ->after('harga_per_satuan');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('harga_pokok');
        });
    }
};
