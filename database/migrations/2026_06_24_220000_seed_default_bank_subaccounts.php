<?php

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migration ini OPSIONAL — TIDAK otomatis menambah sub-akun default.
     *
     * Tujuannya: jadi referensi pola pembuatan sub-akun, dan
     * MENYIAPKAN data structure agar tenant bisa mulai pakai hirarki COA.
     *
     * Tidak menyentuh akun yang sudah ada (111100, 111110, dll) — semuanya
     * tetap sebagai LEAF (postable). User yang akan menambah sub-akun
     * sendiri lewat UI "Tambah Sub-Akun" sesuai kebutuhan bisnisnya.
     *
     * Sengaja kosong: cukup migrate untuk record tracking saja.
     */
    public function up(): void
    {
        // No-op. Sub-akun bank/kas akan dibuat user lewat UI per kebutuhan.
        // Existing accounts (111100, 111110) tetap berfungsi sebagai leaf
        // sampai user mengkonversinya jadi header dengan menambah child.
    }

    public function down(): void
    {
        //
    }
};
