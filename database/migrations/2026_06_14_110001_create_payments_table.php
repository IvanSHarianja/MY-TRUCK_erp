<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('payment_number', 30);
            $table->date('payment_date');

            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();

            // Akun kas/bank yang menerima dana (user pilih)
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();

            $table->decimal('amount', 20, 2);
            $table->string('reference_number', 100)->nullable();  // no transfer / no kwitansi
            $table->text('description')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'payment_number']);
            $table->index(['company_id', 'payment_date']);
            $table->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
