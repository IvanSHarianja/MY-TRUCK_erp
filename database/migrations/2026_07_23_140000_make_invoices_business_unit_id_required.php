<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-03: Invoice wajib punya lini bisnis. Sebelumnya nullable →
 * Laba Rugi Matrix per lini punya bucket "UMUM/Tanpa Lini" yang gelap.
 * Sekarang setiap invoice PASTI ter-alokasi ke RENT/ARMD/MATL/BONG/UMUM.
 *
 * Backfill data lama: invoice yang business_unit_id NULL → set ke BU UMUM
 * per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill NULL → BU UMUM per company (idempotent — cek dulu).
        DB::table('invoices')
            ->whereNull('business_unit_id')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows->groupBy('company_id') as $companyId => $invoices) {
                    $umumId = DB::table('business_units')
                        ->where('company_id', $companyId)
                        ->where('code', 'UMUM')
                        ->value('id');

                    if (! $umumId) continue; // company tanpa BU UMUM — skip

                    DB::table('invoices')
                        ->whereIn('id', $invoices->pluck('id'))
                        ->update(['business_unit_id' => $umumId]);
                }
            });

        // 2. Ubah kolom jadi NOT NULL. FK asli pakai ON DELETE SET NULL
        //    yang mengharuskan kolom nullable — jadi drop FK dulu, alter kolom,
        //    baru re-add FK dengan RESTRICT (BU tidak bisa dihapus kalau
        //    masih dipakai invoice — invoice pasti punya lini bisnis).
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('invoices', function ($table) {
                $table->dropForeign(['business_unit_id']);
            });

            DB::statement('ALTER TABLE invoices MODIFY business_unit_id BIGINT UNSIGNED NOT NULL');

            Schema::table('invoices', function ($table) {
                $table->foreign('business_unit_id')
                    ->references('id')
                    ->on('business_units')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('invoices', function ($table) {
                $table->dropForeign(['business_unit_id']);
            });

            DB::statement('ALTER TABLE invoices MODIFY business_unit_id BIGINT UNSIGNED NULL');

            Schema::table('invoices', function ($table) {
                $table->foreign('business_unit_id')
                    ->references('id')
                    ->on('business_units')
                    ->nullOnDelete();
            });
        }
    }
};
