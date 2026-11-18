<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan material: biaya pengiriman armada per penjualan + margin
 * (masukan atasan Ivan 2026-11-18: "harga penjualan dikurangi pengeluaran
 * ketemu marginnya").
 *
 *   satuan_jual  'satuan' (M³/ton material) | 'rit' (jual per rit)
 *   jumlah_rit   jumlah rit pengiriman (dasar ongkos supir & BM per rit)
 *   asset_id / driver_id — truk & supir pengantar (Laba Rugi per Unit)
 *   ongkos_supir_per_rit, uang_makan_supir, biaya_bbm, bm_per_rit,
 *   biaya_portal, biaya_lain(+_ket) — dibayar tunai dari biaya_cash_account_id
 *   biaya_kirim_total, biaya_journal_id — jurnal BKK biaya kirim
 *   hpp_total    HPP penjualan (dulu hanya di jurnal) — untuk laporan margin
 *
 * `volume` tetap = jumlah keluar dari STOK (satuan material) — stok & HPP
 * selalu dalam satuan material walau dijual per rit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('material_sales', function (Blueprint $t) {
            $t->string('satuan_jual', 10)->default('satuan')->after('volume');
            $t->decimal('jumlah_rit', 8, 2)->nullable()->after('satuan_jual');
            $t->foreignId('asset_id')->nullable()->after('jumlah_rit')->constrained()->nullOnDelete();
            $t->foreignId('driver_id')->nullable()->after('asset_id')->constrained('employees')->nullOnDelete();
            $t->decimal('ongkos_supir_per_rit', 20, 2)->default(0);
            $t->decimal('uang_makan_supir', 20, 2)->default(0);
            $t->decimal('biaya_bbm', 20, 2)->default(0);
            $t->decimal('bm_per_rit', 20, 2)->default(0);
            $t->decimal('biaya_portal', 20, 2)->default(0);
            $t->decimal('biaya_lain', 20, 2)->default(0);
            $t->string('biaya_lain_ket', 150)->nullable();
            $t->decimal('biaya_kirim_total', 20, 2)->default(0);
            $t->foreignId('biaya_cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->foreignId('biaya_journal_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->decimal('hpp_total', 20, 2)->default(0);
        });

        // HPP penjualan lama dari jurnal HPP-{no. penjualan} yang masih posted.
        // MySQL: || = OR (bukan gabung teks) → pakai CONCAT.
        $docNumber = DB::connection()->getDriverName() === 'mysql'
            ? "CONCAT('HPP-', material_sales.sale_number)"
            : "'HPP-' || material_sales.sale_number";

        DB::table('material_sales')->update([
            'hpp_total' => DB::raw("COALESCE((SELECT je.total_amount FROM journal_entries je
                WHERE je.company_id = material_sales.company_id
                  AND je.document_number = {$docNumber}
                  AND je.status = 'posted' LIMIT 1), 0)"),
        ]);
    }

    public function down(): void
    {
        Schema::table('material_sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('asset_id');
            $t->dropConstrainedForeignId('driver_id');
            $t->dropConstrainedForeignId('biaya_cash_account_id');
            $t->dropConstrainedForeignId('biaya_journal_id');
            $t->dropColumn([
                'satuan_jual', 'jumlah_rit', 'ongkos_supir_per_rit', 'uang_makan_supir', 'biaya_bbm', 'bm_per_rit',
                'biaya_portal', 'biaya_lain', 'biaya_lain_ket', 'biaya_kirim_total', 'hpp_total',
            ]);
        });
    }
};
