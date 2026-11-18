<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarif dikunci di setiap log harian saat diinput (keputusan Ivan 2026-11-17).
 *
 * Sebelumnya tagihan memakai tarif kontrak SAAT DITAGIH: tarif yang dinaikkan
 * lewat persetujuan Owner ikut berlaku mundur ke log lama yang belum ditagih —
 * bertentangan dengan janji di layar persetujuan.
 *
 * Backfill: log lama diisi tarif kontrak saat ini (riwayat tarif lama tidak
 * tersimpan di mana pun, jadi ini perkiraan terbaik).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rit_logs', function (Blueprint $t) {
            $t->decimal('tarif', 20, 2)->nullable()->after('hari_kerja');
        });
        Schema::table('rental_logs', function (Blueprint $t) {
            $t->decimal('tarif', 20, 2)->nullable()->after('jam_kerja');
        });

        // Subquery berkorelasi — jalan di MySQL & SQLite.
        DB::table('rit_logs')->whereNull('tarif')->update([
            'tarif' => DB::raw('(SELECT c.tarif_per_rit FROM armada_contracts c WHERE c.id = rit_logs.armada_contract_id)'),
        ]);
        DB::table('rental_logs')->whereNull('tarif')->update([
            'tarif' => DB::raw('(SELECT c.tarif_per_jam FROM rental_contracts c WHERE c.id = rental_logs.rental_contract_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('rit_logs', fn (Blueprint $t) => $t->dropColumn('tarif'));
        Schema::table('rental_logs', fn (Blueprint $t) => $t->dropColumn('tarif'));
    }
};
