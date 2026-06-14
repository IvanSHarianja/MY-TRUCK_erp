<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('invoice_number', 30);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();

            // Akun pendapatan yang akan di-kredit saat invoice di-issue
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            // Akun piutang (default 111200 Piutang Usaha — di-pluck saat issue)
            $table->foreignId('receivable_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->text('description')->nullable();
            $table->decimal('amount', 20, 2)->default(0);
            $table->decimal('paid_amount', 20, 2)->default(0);

            $table->enum('status', ['draft', 'terbit', 'sebagian', 'lunas', 'void'])
                  ->default('draft');

            // Sumber transaksi (untuk auto-posting nanti dari modul operasional)
            $table->string('source_type', 50)->nullable();  // e.g. 'material_sale', 'rental_invoice', 'armada_invoice', 'project_termin'
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'invoice_date']);
            $table->index(['company_id', 'client_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
