<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pekerjaan tambah (addendum / CCO) proyek borongan — masukan 2026-11-18:
 * "override yang menambah piutang pelanggan, wajib persetujuan Owner".
 *
 * Dicatat TERPISAH dari nilai_kontrak (persentase termin tetap terhadap
 * kontrak awal). Setelah disetujui → invoice sebesar nilai pekerjaan tambah
 * (Dr Piutang / Cr Pendapatan Borongan).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_addendums', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('addendum_number', 30);
            $t->date('tanggal');
            $t->string('uraian', 255);
            $t->decimal('nilai', 20, 2);
            $t->string('status', 12)->default('pending');   // pending | approved | rejected | cancelled | void
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->timestamps();

            $t->unique(['company_id', 'addendum_number']);
            $t->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_addendums');
    }
};
