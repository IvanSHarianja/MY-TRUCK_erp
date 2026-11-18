<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemakaian unit sendiri (alat berat & armada) di Proyek Borongan, dicatat
 * harian (masukan atasan Ivan 2026-11-18: "proyek borongan all in, alat berat
 * armada ikut semua").
 *
 * Biaya dibayar tunai → jurnal Dr beban / Cr kas, lini BONG, ditandai unit.
 * Jam/rit menggerakkan penyusutan pemakaian (DEPUSE) bila metode aset per
 * jam/rit/hari. Semua biaya log + penyusutannya = biaya proyek di laporan
 * Margin Proyek.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_unit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained()->restrictOnDelete();
            $t->foreignId('operator_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->date('log_date');
            $t->decimal('jam_kerja', 6, 2)->nullable();
            $t->unsignedInteger('rit_count')->nullable();
            $t->decimal('solar_liter', 10, 2)->default(0);
            $t->decimal('harga_solar', 20, 2)->default(0);
            $t->decimal('biaya_bbm', 20, 2)->default(0);       // = liter × harga (disimpan)
            $t->decimal('gaji', 20, 2)->default(0);            // gaji operator/supir hari itu
            $t->decimal('uang_makan', 20, 2)->default(0);
            $t->decimal('premi', 20, 2)->default(0);           // premi / uang jalan
            $t->decimal('biaya_lain', 20, 2)->default(0);
            $t->string('biaya_lain_ket', 150)->nullable();
            $t->decimal('total_biaya', 20, 2)->default(0);
            $t->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'log_date']);
            $t->index(['company_id', 'asset_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_unit_logs');
    }
};
