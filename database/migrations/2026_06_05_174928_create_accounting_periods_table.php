<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
