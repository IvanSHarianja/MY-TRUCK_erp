<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti Transfer — kolom nullable string di 6 tabel transaksi kas.
 *
 * Storage path pattern: bukti-tf/{company_id}/{YYYY}/{MM}/{uuid}.{ext}
 * Disk: public (storage/app/public → symlinked ke public/storage).
 *
 * `journal_entries.bukti_tf_path` cover 2 alur yang langsung post journal:
 *   - QuickTransaction (Setoran Modal, Bayar Utang, Beban, dll)
 *   - Project DP (via ProjectService::terimaDP)
 *
 * Sisanya kolom di source model — user upload dari halaman transaksi
 * bersangkutan (Payment, MaterialSale, Maintenance, RitLog, RentalLog).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'material_sales', 'asset_maintenance_logs',
                  'rit_logs', 'rental_logs', 'journal_entries'] as $table) {
            if (! Schema::hasColumn($table, 'bukti_tf_path')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('bukti_tf_path')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['payments', 'material_sales', 'asset_maintenance_logs',
                  'rit_logs', 'rental_logs', 'journal_entries'] as $table) {
            if (Schema::hasColumn($table, 'bukti_tf_path')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('bukti_tf_path');
                });
            }
        }
    }
};
