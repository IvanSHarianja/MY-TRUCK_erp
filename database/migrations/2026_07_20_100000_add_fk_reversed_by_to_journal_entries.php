<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-04 — Tambah FK constraint untuk journal_entries.reversed_by_id.
 *
 * MASALAH:
 * Migrasi awal (2026_06_05_174925_create_journal_entries_table.php:37)
 * membuat kolom `reversed_by_id` sebagai unsignedBigInteger biasa TANPA FK.
 *
 *   $table->foreignId('reversed_by_id')->nullable()
 *       ->comment('FK ke journal_entries.id (jurnal pembalik)');
 *
 * `foreignId` tanpa `->constrained()` HANYA bikin kolom, tidak menambah
 * constraint. Akibatnya: kalau ada admin hapus manual jurnal pembalik
 * di DB (mis. via phpMyAdmin), row jurnal asli tetap punya `reversed_by_id`
 * yang menunjuk ke ID yang sudah tidak ada → orphan reference,
 * audit trail putus.
 *
 * FIX:
 * Tambah FK dengan `nullOnDelete()` — kalau jurnal pembalik terhapus,
 * kolom di jurnal asli auto-null (audit trail: jurnal void tapi tidak
 * ada info pembaliknya, lebih baik daripada nunjuk ke ID palsu).
 *
 * Skip di sqlite (test env) karena schema builder Laravel di sqlite
 * kesulitan ALTER add FK — behavior FK ada dari CREATE. Untuk sqlite,
 * kalau memang tabel dibuat baru akan dapat FK dari migrasi asli
 * (yang bakal diupdate di rewrite pattern nanti).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // sqlite/pgsql: skip, FK bisa ditambah manual di rebuild
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('reversed_by_id')
                ->references('id')
                ->on('journal_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['reversed_by_id']);
        });
    }
};
