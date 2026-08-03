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

        // 2. Ubah kolom jadi NOT NULL (MySQL only — SQLite in-memory test
        //    tidak mudah alter, dan tidak perlu — schema utuh dari migrasi awal).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE invoices MODIFY business_unit_id BIGINT UNSIGNED NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE invoices MODIFY business_unit_id BIGINT UNSIGNED NULL');
        }
    }
};
