<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom asset_id di journal_entry_lines untuk cost tracking per unit.
     *
     * Tujuan utama: memungkinkan Laporan Laba Rugi per Unit (per aset spesifik).
     * Contoh: berapa laba/rugi DT-01 bulan ini? = SUM(revenue lines tag DT-01)
     * dikurangi SUM(cost lines tag DT-01).
     *
     * Kolom nullable karena tidak semua line related aset:
     *   - Line kas/piutang (sisi kredit/debit lawan): NULL
     *   - Line beban admin/pajak: NULL (tidak spesifik ke 1 aset)
     *   - Line beban maintenance/BBM/gaji/penyusutan: tagged ke asset_id
     *   - Line pendapatan sewa (RENT): tagged ke asset_id contract
     *   - Line pendapatan armada (ARMD): tagged kalau invoice pakai 1 aset;
     *     kalau multi-aset, di-split multi-line per aset
     *
     * FK dengan nullOnDelete: kalau aset dihapus, line jurnal tetap ada
     * (tidak boleh lost audit trail), asset_id di-null. Report kehilangan
     * tag tapi jurnal utuh.
     *
     * Index (company_id, asset_id, period_year, period_month) — untuk query
     * report P&L per aset per periode (fast aggregation).
     */
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('asset_id')
                ->nullable()
                ->after('account_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('asset_id', 'jel_asset_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('jel_asset_idx');
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
