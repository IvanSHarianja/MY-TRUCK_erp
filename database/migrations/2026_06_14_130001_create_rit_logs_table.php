<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('armada_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();  // Dump Truck
            $table->foreignId('driver_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->date('log_date');
            $table->integer('rit_count');

            // Link ke invoice ketika sudah ditagih (nullable = belum ditagih)
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'log_date']);
            $table->index(['armada_contract_id', 'invoice_id']);  // untuk hitung unbilled
            $table->index(['company_id', 'asset_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rit_logs');
    }
};
