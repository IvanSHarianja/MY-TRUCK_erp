<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-10 — Unique constraint (project_id, termin_number) di project_termins.
 *
 * MASALAH:
 * Migration awal `create_project_termins` hanya bikin INDEX, bukan UNIQUE.
 * Concurrent tagihTermin() bisa hasilkan termin_number sama untuk 1 project
 * (mis. dua-duanya dapat max()+1 = 4 sebelum salah satu commit).
 *
 * FIX:
 * DB-level unique constraint — MySQL tolak insert kedua, service catch
 * UniqueConstraintViolationException dan retry dengan nomor baru.
 * Ini defense-in-depth di atas lockForUpdate yang sudah di-apply di service.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cek dulu apakah unique sudah ada (idempotent guard)
        if (! $this->uniqueExists('project_termins', 'project_termins_project_id_termin_number_unique')) {
            Schema::table('project_termins', function (Blueprint $table) {
                $table->unique(['project_id', 'termin_number']);
            });
        }
    }

    public function down(): void
    {
        if ($this->uniqueExists('project_termins', 'project_termins_project_id_termin_number_unique')) {
            Schema::table('project_termins', function (Blueprint $table) {
                $table->dropUnique(['project_id', 'termin_number']);
            });
        }
    }

    private function uniqueExists(string $table, string $indexName): bool
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
