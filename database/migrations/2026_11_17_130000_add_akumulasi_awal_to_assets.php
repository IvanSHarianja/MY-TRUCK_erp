<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akumulasi penyusutan aset SEBELUM dicatat di MY-TRUCK (aset lama / hasil
 * import). Tanpa ini aset tahun 2015 (umur 8 th) tidak pernah disusutkan
 * (umur sudah habis) tapi nilai bukunya tetap 100%, dan aset 2019 hanya
 * disusutkan sisa bulannya lalu berhenti di ±90% harga.
 *
 * Dipakai DepreciationService (batas: akumulasi ≤ harga − nilai sisa) dan
 * laporan Penyusutan Aset (nilai buku). Saldonya di buku besar tetap dicatat
 * lewat jurnal Saldo Awal (Dr Aset Tetap / Cr Akumulasi / Cr Modal).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->decimal('akumulasi_awal', 20, 2)->default(0)->after('salvage_value');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('akumulasi_awal'));
    }
};
