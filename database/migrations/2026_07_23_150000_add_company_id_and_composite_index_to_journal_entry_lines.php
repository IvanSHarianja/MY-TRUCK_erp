<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-07: Denormalize company_id ke journal_entry_lines.
 *   - Tenant scope defense-in-depth (bisa filter langsung tanpa join JE).
 *   - Bulk operation query cepat.
 *
 * GAP-06: Composite index (company_id, asset_id, period_year, period_month)
 * untuk P&L per aset — sebelumnya cuma single (asset_id) → slow scan.
 * period_year & period_month DILEBURKAN dari join JE via denormalize
 * kolom di jl. Aktualnya karena period ada di JE, kita bikin index
 * (company_id, asset_id) di jl saja — sisa filter period tetap via join.
 *
 * Backfill: copy company_id + period fields dari parent journal_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('journal_entry_lines', 'company_id')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('journal_entry_id');
            });
        }

        // Backfill dari parent JE (cross-driver: MySQL join vs SQLite subquery)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('
                UPDATE journal_entry_lines jl
                JOIN journal_entries je ON je.id = jl.journal_entry_id
                SET jl.company_id = je.company_id
                WHERE jl.company_id IS NULL
            ');
        } else {
            DB::statement('
                UPDATE journal_entry_lines
                SET company_id = (
                    SELECT company_id FROM journal_entries
                    WHERE journal_entries.id = journal_entry_lines.journal_entry_id
                )
                WHERE company_id IS NULL
            ');
        }

        // Composite index (company_id, asset_id) — untuk P&L per aset
        if (! $this->indexExists('journal_entry_lines', 'jl_company_asset_idx')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->index(['company_id', 'asset_id'], 'jl_company_asset_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('journal_entry_lines', 'jl_company_asset_idx')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->dropIndex('jl_company_asset_idx');
            });
        }

        if (Schema::hasColumn('journal_entry_lines', 'company_id')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->dropColumn('company_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $result = DB::select(
                'SELECT COUNT(*) as cnt FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $indexName]
            );
            return ($result[0]->cnt ?? 0) > 0;
        }
        if ($driver === 'sqlite') {
            $result = DB::select(
                "SELECT COUNT(*) as cnt FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$indexName]
            );
            return ($result[0]->cnt ?? 0) > 0;
        }
        return false;
    }
};
