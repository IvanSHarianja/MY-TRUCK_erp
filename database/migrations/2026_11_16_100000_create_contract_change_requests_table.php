<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan perubahan kontrak (Armada & Rental) yang wajib disetujui Owner.
 *
 * Kontrak TIDAK berubah sampai pengajuan disetujui. `changes` menyimpan
 * nilai lama & baru per field: nilai lama dipakai saat approve untuk
 * menolak pengajuan basi (kontrak sudah berubah sejak diajukan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type', 100);           // morph class: ArmadaContract / RentalContract
            $table->unsignedBigInteger('contract_id');
            $table->json('changes');                         // {field: {old, new}}
            $table->text('reason');                          // alasan perubahan (wajib)
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['contract_type', 'contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_change_requests');
    }
};
