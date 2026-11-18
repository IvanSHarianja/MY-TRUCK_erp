<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda tangan online & cap PT (gambar) untuk dicetak di invoice — masukan
 * 2026-11-18. File disimpan di disk privat `local` (bukan publik): PDF
 * membacanya langsung dari server sebagai data URI.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('signature_path')->nullable()->after('logo');
            $t->string('stamp_path')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['signature_path', 'stamp_path']));
    }
};
