<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hutang & cicilan perusahaan (bank, leasing, vendor) — masukan 2026-11-18:
 * "tampilkan hutang/cicilan ke bank atau vendor dengan tanggal tempo".
 *
 * debts             — satu baris per pinjaman/hutang.
 * debt_installments — jadwal cicilan (jatuh tempo, pokok, bunga) + pembayarannya.
 * Pembelian material kredit otomatis membuat hutang vendor 1x bayar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('debt_number', 30);
            $t->string('jenis', 12);                          // bank | leasing | vendor | lainnya
            $t->string('kreditur', 150);
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payable_account_id')->constrained('accounts');
            $t->decimal('pokok', 20, 2);
            $t->decimal('bunga_pct', 7, 4)->default(0);       // % per tahun, flat
            $t->unsignedSmallInteger('tenor_bulan')->default(1);
            $t->date('tanggal_mulai');
            $t->date('jatuh_tempo_pertama');
            $t->string('status', 10)->default('aktif');      // aktif | lunas
            $t->foreignId('disbursement_journal_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->string('source_type', 50)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['company_id', 'debt_number']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('debt_installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('ke');
            $t->date('due_date');
            $t->decimal('pokok', 20, 2);
            $t->decimal('bunga', 20, 2)->default(0);
            $t->string('status', 10)->default('belum');      // belum | lunas
            $t->date('paid_date')->nullable();
            $t->decimal('paid_pokok', 20, 2)->nullable();
            $t->decimal('paid_bunga', 20, 2)->nullable();
            $t->decimal('denda', 20, 2)->nullable();
            $t->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['debt_id', 'ke']);
            $t->index(['company_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_installments');
        Schema::dropIfExists('debts');
    }
};
