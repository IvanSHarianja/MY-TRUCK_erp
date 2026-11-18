<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ongkos gendong (jasa angkut armada) bisa ditagih per M³, per rit, atau
 * per TON (masukan atasan Ivan 2026-11-18). Tonase diisi di log harian dari
 * tiket timbang, untuk kontrak bersatuan tarif 'per_ton'.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rit_logs', function (Blueprint $t) {
            $t->decimal('tonase', 10, 2)->nullable()->after('volume_m3');
        });
    }

    public function down(): void
    {
        Schema::table('rit_logs', fn (Blueprint $t) => $t->dropColumn('tonase'));
    }
};
