<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant role permission override.
 *
 * Setiap PT bisa custom matrix role-nya sendiri. Row di sini = OVERRIDE
 * terhadap default `App\Support\RoleMatrix`. Kalau row tidak ada untuk
 * (company, role, permission) tertentu, fallback ke default.
 *
 * Owner NEVER disimpan di sini — permission owner immutable di-enforce
 * di RoleAccessManager. Table hanya untuk admin/accountant/viewer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);         // admin | accountant | viewer
            $table->string('permission', 60);   // Permission enum value
            $table->boolean('is_granted')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu row per (company, role, permission) — upsert-friendly.
            $table->unique(['company_id', 'role', 'permission'], 'crp_unique');
            $table->index(['company_id', 'role'], 'crp_company_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_role_permissions');
    }
};
