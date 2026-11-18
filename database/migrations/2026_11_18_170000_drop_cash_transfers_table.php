<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu "Transfer Antar Rekening" dibatalkan (keputusan Ivan 2026-11-18):
 * pindah dana antar rekening cukup lewat Jurnal Umum — draft yang semua
 * barisnya kas/bank hanya bisa diposting Owner (JournalService::post).
 *
 * Migrasi pembuatnya sudah dihapus; bersihkan tabel & catatannya di DB yang
 * sempat menjalankannya. Di DB baru keduanya no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('cash_transfers');
        DB::table('migrations')->where('migration', '2026_11_18_140000_create_cash_transfers_table')->delete();
    }

    public function down(): void
    {
        // Tidak dibuat ulang.
    }
};
