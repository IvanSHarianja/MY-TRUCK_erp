<?php

use App\Enums\MaterialJenis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sparepart sebagai persediaan (dipakai sendiri saat servis unit).
 *
 * 1. materials.jenis — memisahkan barang dagangan ('jual') dari sparepart.
 *    Semua material lama default 'jual' → perilaku penjualan tidak berubah.
 *
 * 2. asset_maintenance_log_items — sparepart yang dipakai pada satu servis.
 *    Tabel baris sendiri (bukan kolom di log) karena satu servis lazim memakai
 *    beberapa sparepart sekaligus.
 *
 * unit_cost disimpan per baris = MAC saat sparepart dipakai. Harga pokok harus
 * dibekukan di sini: MAC master berubah setiap pembelian berikutnya, sementara
 * jurnal servis yang sudah terbit tidak boleh ikut berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $t) {
            $t->string('jenis', 20)
                ->default(MaterialJenis::Jual->value)
                ->after('name')
                ->comment('jual = dijual ke klien, sparepart = dipakai sendiri saat servis');
        });

        Schema::create('asset_maintenance_log_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_maintenance_log_id')->constrained()->cascadeOnDelete();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();

            $t->decimal('qty', 15, 2);
            $t->decimal('unit_cost', 15, 4)->comment('MAC sparepart saat dipakai — dibekukan');
            $t->decimal('total', 20, 2)->comment('qty × unit_cost');

            $t->timestamps();

            $t->index(['company_id', 'asset_maintenance_log_id'], 'amli_company_log_idx');
            $t->index(['company_id', 'material_id'], 'amli_company_material_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_log_items');

        Schema::table('materials', function (Blueprint $t) {
            $t->dropColumn('jenis');
        });
    }
};
