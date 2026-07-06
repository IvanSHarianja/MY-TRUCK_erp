<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom default_business_unit_id di assets.
     *
     * Alasan: DepreciationService (fase berikutnya) butuh tahu ke lini bisnis
     * mana biaya penyusutan asset harus di-tag. Tanpa kolom ini, penyusutan
     * selalu jatuh ke UMUM → Income Statement Matrix per lini kurang akurat.
     *
     * Nullable + nullOnDelete: BU boleh belum di-set (fallback dari tipe asset
     * di Asset::defaultBusinessUnitCode()), dan bila BU dihapus assetnya tetap.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('default_business_unit_id')
                ->nullable()
                ->after('account_id')
                ->constrained('business_units')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_business_unit_id');
        });
    }
};
