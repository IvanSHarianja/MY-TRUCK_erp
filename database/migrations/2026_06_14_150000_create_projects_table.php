<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('project_number', 30);
            $table->string('name', 255);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();

            $table->string('jenis_pekerjaan', 100);
            $table->decimal('nilai_kontrak', 20, 2);

            // Progress fisik 0-100%
            $table->decimal('progress_pct', 5, 2)->default(0);

            // Akumulator % yang sudah ditagih
            $table->decimal('tertagih_pct', 5, 2)->default(0);

            // Total uang muka diterima (untuk display, dihitung dari saldo 221170 per proyek nanti)
            $table->decimal('dp_diterima', 20, 2)->default(0);

            $table->enum('status', ['berjalan', 'selesai', 'batal'])->default('berjalan');

            $table->date('started_at')->nullable();
            $table->date('target_end_date')->nullable();
            $table->date('ended_at')->nullable();

            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'project_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
