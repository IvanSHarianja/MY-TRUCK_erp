<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_progress_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->date('update_date');
            $table->decimal('progress_pct', 5, 2);  // %

            $table->text('notes')->nullable();
            $table->string('photo_url', 500)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'update_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_progress_updates');
    }
};
