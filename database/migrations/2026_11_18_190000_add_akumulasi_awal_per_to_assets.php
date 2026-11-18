<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akumulasi penyusutan awal berlaku "s/d akhir bulan X". Tanpa tanggal ini,
 * menjalankan penyusutan untuk bulan yang sudah tercakup (mis. susulan bulan
 * lalu) menyusutkan bulan itu dua kali (audit 2026-11-18).
 *
 * Backfill: akumulasi awal selalu dihitung s/d akhir bulan sebelum aset
 * dicatat → akhir bulan sebelum created_at.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->date('akumulasi_awal_per')->nullable()->after('akumulasi_awal');
        });

        DB::table('assets')->where('akumulasi_awal', '>', 0)->whereNull('akumulasi_awal_per')
            ->orderBy('id')->each(function ($a) {
                $per = \Illuminate\Support\Carbon::parse($a->created_at ?? now())->subMonthNoOverflow()->endOfMonth()->toDateString();
                DB::table('assets')->where('id', $a->id)->update(['akumulasi_awal_per' => $per]);
            });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('akumulasi_awal_per'));
    }
};
