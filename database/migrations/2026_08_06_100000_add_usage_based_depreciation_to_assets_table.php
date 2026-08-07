<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIZ-02: Usage-based Depreciation (PSAK 16 units-of-production).
 *
 * Menambah 4 kolom di `assets`:
 *   - depreciation_method (varchar 20) — dikunci PHP Enum App\Enums\DepreciationMethod.
 *     Pakai varchar (bukan MySQL ENUM) supaya tambah case baru tidak perlu migrasi.
 *   - useful_life_hours / _rits / _days (decimal 12,2 nullable) — umur ekonomis
 *     dalam satuan usage. Decimal(12,2) supaya konsisten dengan input log yang
 *     bisa half-unit (mis. 0.5 hari, 0.5 jam). Nullable karena hanya wajib untuk
 *     method non-straight_line.
 *
 * Backward compatibility: default 'straight_line' → aset existing tetap
 * ke-cron bulanan tanpa perubahan behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('depreciation_method', 20)
                ->default('straight_line')
                ->after('useful_life_months');

            $table->decimal('useful_life_hours', 12, 2)
                ->nullable()
                ->after('depreciation_method');

            $table->decimal('useful_life_rits', 12, 2)
                ->nullable()
                ->after('useful_life_hours');

            $table->decimal('useful_life_days', 12, 2)
                ->nullable()
                ->after('useful_life_rits');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'depreciation_method',
                'useful_life_hours',
                'useful_life_rits',
                'useful_life_days',
            ]);
        });
    }
};
