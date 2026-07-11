<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat pemeliharaan / service per aset.
     *
     * Business decision A3 disetujui 2026-07-06: type enum fix
     * (bukan master table customizable) — 5 tipe:
     *   service_rutin, service_berat, ganti_sparepart,
     *   perbaikan_darurat, inspeksi
     *
     * FK behavior:
     *   - asset_id      cascadeOnDelete: log ikut hilang bila aset dihapus
     *                   (asset delete sudah dijaga model observer, tidak
     *                   akan terjadi kalau ada dependencies)
     *   - vendor_id     nullOnDelete: bengkel bisa berganti / hilang
     *   - journal_entry nullOnDelete: kalau jurnal void terpisah, log tetap
     *
     * next_service_hm dan next_service_date opsional — untuk preventive alert.
     * Tahap 4 (Maintenance Module) akan gunakan ini untuk widget "aset overdue".
     */
    public function up(): void
    {
        Schema::create('asset_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('maintenance_date');
            $table->enum('type', [
                'service_rutin',
                'service_berat',
                'ganti_sparepart',
                'perbaikan_darurat',
                'inspeksi',
            ])->default('service_rutin');
            $table->string('description', 500);
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cost', 20, 2)->default(0);
            $table->decimal('hm_saat_service', 10, 2)->nullable()
                ->comment('Hour meter reading saat service');
            $table->decimal('next_service_hm', 10, 2)->nullable()
                ->comment('HM target service berikutnya (preventive)');
            $table->date('next_service_date')->nullable();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->string('photo_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'maintenance_date']);
            $table->index(['asset_id', 'maintenance_date']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_logs');
    }
};
