<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-14 — Ubah FK asset_maintenance_logs.asset_id dari cascadeOnDelete
 * ke restrictOnDelete.
 *
 * MASALAH:
 * Migration awal 2026_07_06_110006 pakai cascadeOnDelete → saat asset
 * dihapus, MySQL auto-hapus semua maintenance_logs terkait TANPA trigger
 * Eloquent observer. Akibatnya: jurnal BBK-MT-* di GL tetap posted
 * (tidak di-void) → orphan reference + audit trail putus.
 *
 * FIX:
 * Ubah ke restrictOnDelete — MySQL tolak delete asset kalau masih ada
 * maintenance_logs. User HARUS delete log manual via Eloquent (yang
 * trigger observer void jurnal) sebelum delete asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // sqlite: FK behavior lebih permissive, skip
        }

        Schema::table('asset_maintenance_logs', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('asset_maintenance_logs', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();
        });
    }
};
