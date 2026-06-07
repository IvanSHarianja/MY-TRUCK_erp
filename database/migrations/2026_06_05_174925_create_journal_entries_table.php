<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('entry_number', 20);
            $table->date('entry_date');
            $table->string('document_number', 50)->nullable();
            $table->enum('document_type', [
                'manual',
                'invoice',
                'bkm',
                'bkk',
                'jual_beli',
                'penyusutan',
                'penyesuaian',
                'penutup',
                'pembalik',
                'saldo_awal',
            ])->default('manual');
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->decimal('total_amount', 20, 2)->default(0);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by_id')->nullable()->comment('FK ke journal_entries.id (jurnal pembalik)');
            $table->timestamps();

            $table->unique(['company_id', 'entry_number']);
            $table->index(['company_id', 'period_year', 'period_month']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
