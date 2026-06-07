<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('parent_code', 10)->nullable();
            $table->string('name');
            $table->enum('category', ['aset', 'kewajiban', 'ekuitas', 'pendapatan', 'beban', 'penutup']);
            $table->string('sub_category')->nullable();
            $table->enum('normal_balance', ['debit', 'kredit']);
            $table->enum('cash_flow_category', ['operasi', 'investasi', 'pendanaan', 'non_kas'])->nullable();
            $table->string('tax_type')->default('non_pajak');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
