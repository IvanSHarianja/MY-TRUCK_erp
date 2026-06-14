<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armada_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('contract_number', 30);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();

            $table->string('route_description', 500);
            $table->decimal('tarif_per_rit', 20, 2);

            // Counter rit yang sudah ditagih (accumulator). Total rit dihitung dari rit_logs sum.
            $table->integer('billed_rit')->default(0);

            $table->enum('status', ['aktif', 'selesai', 'batal'])->default('aktif');

            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'contract_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armada_contracts');
    }
};
