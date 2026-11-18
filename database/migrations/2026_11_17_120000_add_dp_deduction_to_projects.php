<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DP proyek dipotong PROPORSIONAL di setiap termin (keputusan Ivan 2026-11-17).
 *
 * Dulu DP tercatat di Uang Muka Proyek (kewajiban) dan tidak pernah dipotong:
 * kewajiban menggantung selamanya dan termin terakhir ditolak karena
 * "DP + tertagih > nilai kontrak".
 *
 *   projects.dp_dipotong        — total DP yang sudah dipotongkan ke termin
 *   project_termins.dp_potong   — potongan DP di termin ini
 *   project_termins.dp_journal_id — jurnal Dr Uang Muka / Cr Pendapatan
 *   project_termins.amount tetap = nilai BRUTO termin; invoice = bruto − dp_potong
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->decimal('dp_dipotong', 20, 2)->default(0)->after('dp_diterima');
        });

        Schema::table('project_termins', function (Blueprint $t) {
            $t->decimal('dp_potong', 20, 2)->default(0)->after('amount');
            $t->foreignId('dp_journal_id')->nullable()->after('invoice_id')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_termins', function (Blueprint $t) {
            $t->dropConstrainedForeignId('dp_journal_id');
            $t->dropColumn('dp_potong');
        });
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('dp_dipotong'));
    }
};
