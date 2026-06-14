<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_termins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->integer('termin_number');           // urutan termin (1, 2, 3, ...)
            $table->decimal('termin_pct', 5, 2);        // % dari nilai kontrak
            $table->decimal('amount', 20, 2);           // nilai termin

            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->text('description')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'termin_number']);
            $table->index(['company_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_termins');
    }
};
