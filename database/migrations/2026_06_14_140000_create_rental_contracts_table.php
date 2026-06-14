<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('contract_number', 30);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();  // Alat berat

            $table->decimal('tarif_per_jam', 20, 2);
            $table->string('lokasi_kerja', 500)->nullable();

            // Counter jam yang sudah ditagih (akumulator). Total dihitung dari rental_logs.
            $table->decimal('billed_jam', 10, 2)->default(0);

            $table->enum('status', ['aktif', 'selesai', 'batal'])->default('aktif');

            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'contract_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'client_id']);
            $table->index(['company_id', 'asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_contracts');
    }
};
