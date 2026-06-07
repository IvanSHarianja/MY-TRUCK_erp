<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('employee_id', 20);
            $table->string('name');
            $table->enum('position', ['driver', 'operator', 'mandor', 'admin', 'mekanik', 'lainnya'])->default('driver');
            $table->foreignId('assigned_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->date('join_date')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
