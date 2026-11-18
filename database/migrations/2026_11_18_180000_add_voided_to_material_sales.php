<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan material TUNAI bisa dibatalkan (audit 2026-11-18): jurnal
 * penjualan di-void, stok kembali, HPP & biaya kirim dibalik. Penjualan tetap
 * tersimpan (jejak audit) dengan penanda batal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('material_sales', function (Blueprint $t) {
            $t->timestamp('voided_at')->nullable()->after('journal_entry_id');
            $t->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $t->string('void_reason', 255)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('material_sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('voided_by');
            $t->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
