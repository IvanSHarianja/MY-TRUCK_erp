<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('asset_code', 20);
            $table->string('name');
            $table->enum('type', [
                'dump_truck',
                'excavator',
                'bulldozer',
                'wheel_loader',
                'kendaraan_operasional',
                'peralatan_kantor',
                'lainnya',
            ]);
            $table->string('plate_number', 20)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 20, 2)->default(0);
            $table->unsignedInteger('useful_life_months')->default(60);
            $table->decimal('salvage_value', 20, 2)->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->enum('status', ['aktif', 'maintenance', 'non_aktif'])->default('aktif');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'asset_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
