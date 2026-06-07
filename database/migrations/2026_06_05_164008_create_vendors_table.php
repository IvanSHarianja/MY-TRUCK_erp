<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('type', ['kuari', 'bbm', 'sparepart', 'jasa', 'leasing', 'lainnya'])->default('lainnya');
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
