<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('rental_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->date('log_date');
            $table->decimal('hm_awal', 10, 2);
            $table->decimal('hm_akhir', 10, 2);
            $table->decimal('jam_kerja', 10, 2);  // hm_akhir - hm_awal

            $table->decimal('solar_liter', 10, 2)->nullable();
            $table->string('voucher_solar', 50)->nullable();

            // Link ke invoice ketika ditagih
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'log_date']);
            $table->index(['rental_contract_id', 'invoice_id']);
            $table->index(['company_id', 'asset_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_logs');
    }
};
