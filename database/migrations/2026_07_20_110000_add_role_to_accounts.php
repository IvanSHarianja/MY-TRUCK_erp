<?php

use App\Enums\AccountRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.5 Phase 1 — Tambah kolom accounts.role.
 *
 * TUJUAN:
 * Menggantikan hardcoded code (111100, 331100, dll) di service layer dengan
 * role-based lookup. User bebas pakai kode akun apapun; service query berdasarkan
 * role fungsional (cash, equity_modal, cogs_material, dll).
 *
 * MIGRATION STRATEGY:
 * 1. Tambah kolom `role` nullable + index (untuk perf lookup di service).
 * 2. Backfill role untuk akun existing berdasarkan code standar MY-TRUCK
 *    (AccountRole::standardCodeMapping()).
 * 3. Akun custom (yang code-nya tidak di mapping) tetap role=null — user harus
 *    isi manual via UI, dengan hint dari AccountForm.
 *
 * SAFE UNTUK REVERSIBLE:
 * down() cuma drop kolom & index — tidak menghapus data lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: skip create kolom kalau sudah ada (mis. dari migrasi
        // sebelumnya yang gagal tengah jalan dan meninggalkan state parsial).
        if (! Schema::hasColumn('accounts', 'role')) {
            Schema::table('accounts', function (Blueprint $table) {
                // Nullable karena backward compat — akun lama boleh belum punya role.
                // Service defensive kalau null (falls back to code-based lookup).
                $table->string('role', 40)->nullable()->after('cash_flow_category');
            });
        }

        // Index (company_id, role) — service filter selalu per tenant.
        // Tidak boleh unique karena beberapa role boleh multi-akun
        // (contoh: role 'cash' bisa dipakai Kas BCA, Kas Mandiri, Kas Kecil, dll).
        // Idempotent guard: cek existence via information_schema.
        if (! $this->indexExists('accounts', 'accounts_company_role_idx')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->index(['company_id', 'role'], 'accounts_company_role_idx');
            });
        }

        // === Backfill role untuk akun standar existing ===
        // PHP quirk: array key numeric-looking string auto-cast ke int
        // (e.g. ['111100' => ...] → int 111100). MySQL strict compare akan
        // fail saat where('code', 111100) vs varchar '111100-01' → cast double.
        // Cast eksplisit ke string untuk safety cross-driver.
        $mapping = AccountRole::standardCodeMapping();

        foreach ($mapping as $code => $role) {
            DB::table('accounts')
                ->where('code', (string) $code)
                ->whereNull('role')
                ->update(['role' => $role, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if ($this->indexExists('accounts', 'accounts_company_role_idx')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropIndex('accounts_company_role_idx');
            });
        }

        if (Schema::hasColumn('accounts', 'role')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    /**
     * Cek apakah index ada — cross-driver (MySQL + SQLite).
     */
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
