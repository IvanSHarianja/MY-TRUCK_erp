<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrak Armada: satuan tarif (per rit / per M³ / per hari) + Ongkos Gendong
 * (mobilisasi alat berat) yang ditagih bersama dalam satu invoice.
 *
 * Kolom `tarif_per_rit` TIDAK di-rename: artinya kini "tarif per satuan"
 * (lihat ArmadaContract::SATUAN). Kontrak lama otomatis 'per_rit' → tidak
 * ada perubahan perilaku untuk data yang sudah ada.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('armada_contracts', function (Blueprint $t) {
            $t->string('satuan_tarif', 10)->default('per_rit')->after('route_description');
        });

        Schema::table('rit_logs', function (Blueprint $t) {
            // Per M³: volume dari surat jalan / tiket timbang hari itu.
            $t->decimal('volume_m3', 10, 2)->nullable()->after('rit_count');
            // Per hari: 1 = sehari penuh, 0.5 = setengah hari.
            $t->decimal('hari_kerja', 4, 1)->default(1)->after('volume_m3');
        });

        Schema::create('armada_mobilizations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('armada_contract_id')->constrained()->cascadeOnDelete();
            $t->date('tanggal');
            $t->string('jenis', 15)->default('mobilisasi');           // mobilisasi | demobilisasi
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();   // truk self loader
            $t->string('alat_diangkut', 150);                          // mis. "Excavator PC 75"
            $t->decimal('amount', 20, 2);
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['armada_contract_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armada_mobilizations');

        Schema::table('rit_logs', function (Blueprint $t) {
            $t->dropColumn(['volume_m3', 'hari_kerja']);
        });

        Schema::table('armada_contracts', function (Blueprint $t) {
            $t->dropColumn('satuan_tarif');
        });
    }
};
