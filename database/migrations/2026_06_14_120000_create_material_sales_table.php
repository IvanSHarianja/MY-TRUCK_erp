<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('sale_number', 30);
            $table->date('sale_date');

            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();

            $table->decimal('volume', 15, 2);
            $table->decimal('harga_satuan', 20, 2);
            $table->decimal('total', 20, 2);

            // tunai (langsung kas) atau invoice (piutang)
            $table->enum('metode', ['tunai', 'invoice'])->default('tunai');

            // Akun kas yang menerima (jika tunai). Default 111100 Kas dan Bank.
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            // Link ke invoice jika metode invoice
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            // Link ke jurnal jika metode tunai (langsung post)
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'sale_number']);
            $table->index(['company_id', 'sale_date']);
            $table->index(['company_id', 'client_id']);
            $table->index(['company_id', 'metode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_sales');
    }
};
