<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard Operasional: target bulanan per-aset untuk Utilization Rate.
 * Nullable — kalau kosong, fallback ke formula useful_life_hours/60 atau default 200.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->decimal('monthly_target_hours', 8, 2)->nullable()
                ->after('useful_life_days')
                ->comment('Target jam kerja per bulan untuk widget Utilization Dashboard. Nullable → auto-fallback ke useful_life_hours/60 atau default 200.');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->dropColumn('monthly_target_hours');
        });
    }
};
