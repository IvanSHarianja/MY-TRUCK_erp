<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah nilai 'quick_tx' ke enum journal_entries.document_type.
     *
     * Alasan: QuickTransactionService::post() memakai document_type='quick_tx'
     * untuk memudahkan filter riwayat transaksi di halaman "Transaksi & Beban
     * Terpadu", tapi nilai ini belum terdaftar di enum tabel — setiap post
     * gagal dengan MySQL error "Data truncated for column 'document_type'".
     *
     * Laravel schema builder tidak native support modify enum → pakai raw
     * ALTER TABLE. Urutan nilai lama dipertahankan agar backward-compatible
     * untuk data existing.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE journal_entries MODIFY COLUMN document_type "
            . "ENUM('manual','invoice','bkm','bkk','jual_beli','penyusutan','penyesuaian',"
            . "'penutup','pembalik','saldo_awal','quick_tx') NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        // Hapus row dengan document_type='quick_tx' dulu bila ada, agar rollback aman.
        DB::table('journal_entries')->where('document_type', 'quick_tx')->delete();

        DB::statement("ALTER TABLE journal_entries MODIFY COLUMN document_type "
            . "ENUM('manual','invoice','bkm','bkk','jual_beli','penyusutan','penyesuaian',"
            . "'penutup','pembalik','saldo_awal') NOT NULL DEFAULT 'manual'");
    }
};
