<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('owner_name')->nullable();
            $table->year('fiscal_year')->default(date('Y'));
            $table->date('fiscal_start')->nullable();
            $table->date('fiscal_end')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
