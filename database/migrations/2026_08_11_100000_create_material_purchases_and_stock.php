<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIZ-01: Material Purchase + Stock Movement + Moving Average Cost.
 *
 * - material_purchases      → header pembelian dari vendor
 * - material_stock_movements → audit log setiap pergerakan stok (in/out/adjust)
 * - alter materials         → tambah current_stock + current_mac (denormalized)
 *
 * MAC (Moving Average Cost) auto-recalc saat purchase.
 * Stock updated real-time via observer/service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('purchase_number', 20);
            $t->date('purchase_date');
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();

            $t->decimal('qty', 15, 2);
            $t->integer('unit_price');     // Rp per unit (integer — bug rupiah 100× lessons)
            $t->integer('total_amount');   // qty × unit_price (dihitung saat save)

            // Pembayaran: tunai (langsung Kas) atau kredit (Utang Vendor)
            $t->enum('payment_method', ['tunai', 'kredit'])->default('tunai');
            $t->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $t->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->text('notes')->nullable();
            $t->string('bukti_tf_path')->nullable();

            $t->timestamps();

            $t->unique(['company_id', 'purchase_number']);
            $t->index(['company_id', 'purchase_date']);
            $t->index(['company_id', 'material_id']);
            $t->index(['company_id', 'vendor_id']);
        });

        Schema::create('material_stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('material_id')->constrained()->restrictOnDelete();

            $t->enum('movement_type', ['in', 'out', 'adjustment'])->comment('in=purchase, out=sale, adjustment=manual koreksi');
            // Polymorphic — bisa merujuk material_purchases atau material_sales
            $t->string('source_type', 50)->nullable()->comment('MaterialPurchase, MaterialSale, atau manual');
            $t->unsignedBigInteger('source_id')->nullable();

            $t->decimal('qty_change', 15, 2)->comment('+positif=masuk, -negatif=keluar');
            $t->decimal('unit_cost', 15, 2)->comment('Harga per unit saat pergerakan — untuk audit MAC');

            $t->decimal('stock_before', 15, 2);
            $t->decimal('stock_after', 15, 2);
            $t->decimal('mac_before', 15, 4)->comment('MAC sebelum pergerakan — 4 desimal untuk akurasi');
            $t->decimal('mac_after', 15, 4);

            $t->date('movement_date');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            $t->index(['company_id', 'material_id', 'movement_date'], 'msm_company_material_date_idx');
            $t->index(['source_type', 'source_id'], 'msm_source_idx');
        });

        // Tambah kolom denormalized di materials untuk fast lookup.
        // Source of truth tetap material_stock_movements (bisa recompute kapan saja).
        Schema::table('materials', function (Blueprint $t) {
            $t->decimal('current_stock', 15, 2)->default(0)->after('harga_pokok')
                ->comment('Denormalized dari material_stock_movements.stock_after terakhir');
            $t->decimal('current_mac', 15, 4)->default(0)->after('current_stock')
                ->comment('Moving Average Cost — recalc saat setiap purchase (weighted avg)');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $t) {
            $t->dropColumn(['current_stock', 'current_mac']);
        });
        Schema::dropIfExists('material_stock_movements');
        Schema::dropIfExists('material_purchases');
    }
};
