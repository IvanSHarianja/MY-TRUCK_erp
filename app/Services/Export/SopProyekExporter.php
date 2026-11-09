<?php

namespace App\Services\Export;

use App\Models\Account;
use App\Models\Company;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "SOP Proyek Borongan - Armada.xlsx": Proyek, Progress, DP & Termin +
 * jembatan Master Klien.
 *
 * Sumber MY-TRUCK:
 *   - DP     = jurnal "DP-{proyek}" ber-source Project (ProjectService::terimaDP)
 *   - Termin = project_termins + invoice-nya (ProjectService::tagihTermin)
 * Angka ringkasan proyek (DP diterima, progress, termin ditagih) dihitung
 * s.d. SAMPAI dan menimpa rumus SOP yang hanya menjumlah baris di file.
 */
class SopProyekExporter extends SopWorkbookExporter
{
    public const PROYEK_LAST_ROW   = 311;
    public const PROGRESS_LAST_ROW = 2011;
    public const DP_LAST_ROW       = 1011;

    public function __construct(private SopMasterData $master) {}

    public function file(): SopFile
    {
        return SopFile::Proyek;
    }

    public function buildPayload(Company $company, CarbonInterface $dari, CarbonInterface $sampai, ?string $exportedBy = null): array
    {
        [$dari, $sampai, $dariStr, $untilStr] = $this->period($dari, $sampai);
        $cid = $company->id;

        // ── Data pendukung s.d. SAMPAI ──────────────────────────────────
        $dpJournals = DB::table('journal_entries')
            ->where('company_id', $cid)
            ->where('source_type', (new Project)->getMorphClass())
            ->whereIn('status', ['posted', 'void'])
            ->where('document_type', '!=', 'pembalik')
            ->where('entry_date', '<', $untilStr)
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        $termins = DB::table('project_termins as t')
            ->leftJoin('invoices as i', 'i.id', '=', 't.invoice_id')
            ->where('t.company_id', $cid)
            ->select('t.*', 'i.invoice_number', 'i.invoice_date', 'i.status as invoice_status')
            ->get()
            // Tanggal termin = tanggal invoice-nya; tanpa invoice pakai tanggal input.
            ->each(fn ($t) => $t->tanggal = substr((string) ($t->invoice_date ?? $t->created_at), 0, 10))
            ->filter(fn ($t) => $t->tanggal < $untilStr)
            ->sortBy([['tanggal', 'asc'], ['termin_number', 'asc']])
            ->values();

        $progress = DB::table('project_progress_updates')
            ->where('company_id', $cid)
            ->where('update_date', '<', $untilStr)
            ->orderBy('update_date')->orderBy('id')
            ->get();

        // ── Proyek ──────────────────────────────────────────────────────
        $projects = DB::table('projects as p')
            ->leftJoin('clients as c', 'c.id', '=', 'p.client_id')
            ->where('p.company_id', $cid)
            ->where(fn ($q) => $q->whereNull('p.started_at')->orWhere('p.started_at', '<', $untilStr))
            // Proyek yang sudah tutup sebelum DARI tidak diekspor.
            ->where(fn ($q) => $q->where('p.status', 'berjalan')->orWhereNull('p.ended_at')->orWhere('p.ended_at', '>=', $dariStr))
            ->orderBy('p.started_at')->orderBy('p.project_number')
            ->select('p.*', 'c.code as client_code')
            ->get();
        $this->assertCapacity('Proyek', $projects->count(), self::PROYEK_LAST_ROW);

        $proyek = [];
        $row = self::FIRST_ROW;
        foreach ($projects as $p) {
            $dp = $dpJournals->where('source_id', $p->id)->where('status', 'posted')->sum('total_amount');
            $aktifTermin = $termins->where('project_id', $p->id)->filter(fn ($t) => $t->invoice_status !== 'void');
            $lastProgress = $progress->where('project_id', $p->id)->last();

            $proyek += $this->dateParts('E', $row, $p->started_at);
            $proyek += $this->dateParts('N', $row, $p->target_end_date);
            $proyek += [
                "C{$row}"  => $p->project_number,        // timpa rumus PR-NNN
                "H{$row}"  => $p->client_code,
                "J{$row}"  => $p->name,
                "K{$row}"  => $p->jenis_pekerjaan,
                "L{$row}"  => $this->money($p->nilai_kontrak),
                "Q{$row}"  => SopLookups::STATUS_PROYEK[$p->status] ?? null,
                "R{$row}"  => SopLookups::APPROVED,
                "S{$row}"  => round((float) $dp, 2),
                "T{$row}"  => $lastProgress ? (float) $lastProgress->progress_pct : (float) $p->progress_pct,
                "U{$row}"  => round((float) $aktifTermin->sum('termin_pct'), 2),
                "V{$row}"  => round((float) $aktifTermin->sum('amount'), 2),
                "AA{$row}" => trim(implode(' • ', array_filter([$p->description, $p->notes]))) ?: null,
            ];
            $row++;
        }

        $numbers = $projects->pluck('project_number', 'id');
        $allNumbers = DB::table('projects')->where('company_id', $cid)->pluck('project_number', 'id');

        // ── Progress (dalam periode) ────────────────────────────────────
        $progressInPeriod = $progress->filter(fn ($u) => $u->update_date >= $dariStr)->values();
        $this->assertCapacity('Progress', $progressInPeriod->count(), self::PROGRESS_LAST_ROW);

        $prog = [];
        $row = self::FIRST_ROW;
        foreach ($progressInPeriod as $u) {
            $prog += $this->dateParts('E', $row, $u->update_date);
            $prog += [
                "H{$row}" => $numbers[$u->project_id] ?? $allNumbers[$u->project_id] ?? null,
                "J{$row}" => (float) $u->progress_pct,
                "N{$row}" => $u->notes,
                "O{$row}" => $u->photo_url,
            ];
            $row++;
        }

        // ── DP & Termin (dalam periode), urut tanggal ───────────────────
        $cashIds = array_flip(Account::cashAccountIds($cid));
        $dpCash  = DB::table('journal_entry_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('jl.journal_entry_id', $dpJournals->pluck('id')->all())
            ->where('jl.debit', '>', 0)
            ->select('jl.journal_entry_id', 'jl.account_id', 'a.name')
            ->get()
            ->filter(fn ($l) => isset($cashIds[$l->account_id]))
            ->pluck('name', 'journal_entry_id');

        $entries = collect();
        foreach ($dpJournals->filter(fn ($j) => substr((string) $j->entry_date, 0, 10) >= $dariStr) as $j) {
            $entries->push(['date' => substr((string) $j->entry_date, 0, 10), 'kind' => 'DP', 'row' => $j]);
        }
        foreach ($termins->filter(fn ($t) => $t->tanggal >= $dariStr) as $t) {
            $entries->push(['date' => $t->tanggal, 'kind' => 'Termin', 'row' => $t]);
        }
        $entries = $entries->sortBy('date')->values();
        $this->assertCapacity('DP & Termin', $entries->count(), self::DP_LAST_ROW);

        $dpTermin = [];
        $row = self::FIRST_ROW;
        foreach ($entries as $e) {
            $r = $e['row'];
            $dpTermin += $this->dateParts('E', $row, $e['date']);

            if ($e['kind'] === 'DP') {
                $dpTermin += [
                    "C{$row}" => $r->document_number ?: $r->entry_number,
                    "H{$row}" => $allNumbers[$r->source_id] ?? null,
                    "M{$row}" => 'DP',
                    "O{$row}" => $this->money($r->total_amount),
                    "Q{$row}" => $dpCash[$r->id] ?? null,
                    "R{$row}" => $r->status === 'void' ? 'Batal' : 'Diajukan',
                    "T{$row}" => trim("Jurnal {$r->entry_number}" . ($r->description ? " • {$r->description}" : '')),
                ];
            } else {
                $dpTermin += [
                    "C{$row}" => $r->invoice_number ?: "Termin {$r->termin_number}",
                    "H{$row}" => $allNumbers[$r->project_id] ?? null,
                    "M{$row}" => 'Termin',
                    "N{$row}" => (float) $r->termin_pct,
                    "P{$row}" => $this->money($r->amount),   // timpa rumus %×nilai: nominal MY-TRUCK
                    "R{$row}" => $r->invoice_status === 'void' ? 'Batal' : 'Diajukan',
                    "T{$row}" => trim("Termin ke-{$r->termin_number}" . ($r->description ? " • {$r->description}" : '')),
                ];
            }
            $row++;
        }

        return [
            'cells' => [
                'Proyek'       => $proyek,
                'Progress'     => $prog,
                'DP & Termin'  => $dpTermin,
                'Master Klien' => $this->master->clientMirrorCells($cid),
                'Parameter'    => $this->parameterCells($company, $dari, $sampai, $exportedBy,
                    'DP diterima, progress & termin ditagih per proyek dihitung s.d. tanggal SAMPAI dari data MY-TRUCK.'),
            ],
            'clear'    => [],
            'counts'   => ['proyek' => $projects->count(), 'progress' => $progressInPeriod->count(), 'dp & termin' => $entries->count()],
            'warnings' => [],
        ];
    }
}
