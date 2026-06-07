<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('color', 7)->default('#3B82F6');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_units');
    }
};
