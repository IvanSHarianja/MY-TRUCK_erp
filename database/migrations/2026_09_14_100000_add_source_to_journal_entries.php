<?php

use App\Enums\DepreciationMethod;
use App\Models\Project;
use App\Models\RentalLog;
use App\Models\RitLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Link struktural jurnal → dokumen sumber, untuk cascade void.
 *
 * MASALAH:
 *   JournalEntryObserver menentukan cascade dari prefix document_number
 *   ("DP-{project_number}", "DEPUSE-%-{log_id}"). No. Bukti bisa diketik bebas
 *   di jurnal manual → void jurnal koreksi ber-No. Bukti "DP-PR26-0003" ikut
 *   memotong dp_diterima proyek. Selain itu RentalLog #12 dan RitLog #12
 *   sama-sama menghasilkan "DEPUSE-{asset}-12", jadi void salah satu ikut
 *   mem-void DEPUSE log tabel lain.
 *
 * SOLUSI:
 *   source_type/source_id diisi auto-journal yang cascade-nya tidak punya FK
 *   balik: DP proyek (→ Project) dan DEPUSE (→ RentalLog/RitLog). Jurnal lain
 *   (invoice, payment, pembelian, BBK log) sudah punya FK balik
 *   `{tabel}.journal_entry_id`, jadi tidak perlu diisi.
 *
 * BACKFILL (data existing):
 *   - DP-*    : cocokkan project_number di company yang sama + document_type 'bkm'.
 *               Jurnal manual lama yang kebetulan persis "DP-{project_number}"
 *               tidak bisa dibedakan — risiko sama dengan perilaku sebelumnya.
 *   - DEPUSE-*: parse {asset}-{log}, cocokkan ke log yang asset_id-nya sama.
 *               Kalau cocok di dua tabel (sangat jarang), pakai metode aset
 *               (per_hour → RentalLog, per_rit → RitLog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->string('source_type', 100)->nullable()->after('document_type');
            $t->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $t->index(['company_id', 'source_type', 'source_id'], 'journal_entries_source_index');
        });

        $this->backfillProjectDp();
        $this->backfillDepuse();
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->dropIndex('journal_entries_source_index');
            $t->dropColumn(['source_type', 'source_id']);
        });
    }

    private function backfillProjectDp(): void
    {
        DB::table('journal_entries')
            ->where('document_number', 'like', 'DP-%')
            ->where('document_type', 'bkm')
            ->whereNull('source_type')
            ->orderBy('id')
            ->chunkById(500, function ($entries) {
                foreach ($entries as $entry) {
                    $projectId = DB::table('projects')
                        ->where('company_id', $entry->company_id)
                        ->where('project_number', substr($entry->document_number, 3))
                        ->value('id');

                    if ($projectId) {
                        DB::table('journal_entries')->where('id', $entry->id)->update([
                            'source_type' => Project::class,
                            'source_id'   => $projectId,
                        ]);
                    }
                }
            });
    }

    private function backfillDepuse(): void
    {
        $unresolved = 0;

        DB::table('journal_entries')
            ->where('document_number', 'like', 'DEPUSE-%')
            ->whereNull('source_type')
            ->orderBy('id')
            ->chunkById(500, function ($entries) use (&$unresolved) {
                foreach ($entries as $entry) {
                    if (! preg_match('/^DEPUSE-(\d+)-(\d+)$/', $entry->document_number, $m)) {
                        $unresolved++;
                        continue;
                    }
                    [$assetId, $logId] = [(int) $m[1], (int) $m[2]];

                    $matchesLog = fn (string $table): bool => DB::table($table)
                        ->where('company_id', $entry->company_id)
                        ->where('id', $logId)
                        ->where('asset_id', $assetId)
                        ->exists();

                    $inRental = $matchesLog('rental_logs');
                    $inRit    = $matchesLog('rit_logs');

                    if ($inRental && $inRit) {
                        $method = DB::table('assets')->where('id', $assetId)->value('depreciation_method');
                        $inRental = $method === DepreciationMethod::PerHour->value;
                        $inRit    = $method === DepreciationMethod::PerRit->value;
                    }

                    $sourceType = match (true) {
                        $inRental && ! $inRit => RentalLog::class,
                        $inRit && ! $inRental => RitLog::class,
                        default               => null,
                    };

                    if (! $sourceType) {
                        $unresolved++;
                        continue;
                    }

                    DB::table('journal_entries')->where('id', $entry->id)->update([
                        'source_type' => $sourceType,
                        'source_id'   => $logId,
                    ]);
                }
            });

        if ($unresolved > 0) {
            // Biasanya log sudah dihapus / asset diganti — jurnalnya sudah void.
            // Kalau ada yang masih posted, void manual saat log terkait diedit.
            Log::warning("Backfill journal_entries.source: {$unresolved} jurnal DEPUSE tidak bisa dipetakan ke log.");
        }
    }
};
