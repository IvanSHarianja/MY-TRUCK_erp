<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectUnitLog;
use Illuminate\Support\Collection;

/**
 * Margin proyek borongan = pendapatan termin − biaya pemakaian unit sendiri
 * (kas: BBM, gaji, uang makan, premi, lain-lain + penyusutan pemakaian).
 *
 * Pendapatan = nilai BRUTO termin yang sudah ditagih (termasuk bagian yang
 * dipotong dari uang muka). Termin dari invoice yang di-void sudah terhapus.
 *
 * Batas: biaya lain yang dijurnal manual (subkontraktor, material) belum
 * terhubung ke proyek tertentu — hanya biaya dari log pemakaian unit.
 */
class ProjectMarginService
{
    /**
     * @return array{rows: Collection, totals: array{pendapatan: float, biaya: float, margin: float, pct: float}}
     */
    public function report(int $companyId, ?string $status = null): array
    {
        $projects = Project::withoutGlobalScopes()
            ->with('client')
            ->where('company_id', $companyId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('project_number')
            ->get();

        $ids = $projects->pluck('id');
        $termins = \App\Models\ProjectTermin::withoutGlobalScopes()->whereIn('project_id', $ids)
            ->groupBy('project_id')->selectRaw('project_id, SUM(amount) AS total')->pluck('total', 'project_id');

        $logs = ProjectUnitLog::withoutGlobalScopes()->with('asset')->whereIn('project_id', $ids)->get();
        $depuse = $logs->isEmpty() ? collect() : JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('source_type', (new ProjectUnitLog())->getMorphClass())
            ->whereIn('source_id', $logs->pluck('id'))
            ->where('document_number', 'like', 'DEPUSE-%')
            ->where('status', 'posted')
            ->groupBy('source_id')
            ->selectRaw('source_id, SUM(total_amount) AS total')
            ->pluck('total', 'source_id');

        $rows = $projects->map(function (Project $p) use ($termins, $logs, $depuse) {
            $own = $logs->where('project_id', $p->id);

            $units = $own->groupBy('asset_id')->map(fn ($g) => [
                'asset'      => $g->first()->asset,
                'hari'       => $g->count(),
                'jam'        => (float) $g->sum('jam_kerja'),
                'rit'        => (int) $g->sum('rit_count'),
                'biaya_kas'  => (float) $g->sum('total_biaya'),
                'penyusutan' => (float) $g->sum(fn ($l) => (float) ($depuse[$l->id] ?? 0)),
            ])->values();

            $pendapatan = (float) ($termins[$p->id] ?? 0);
            $biaya = (float) $units->sum('biaya_kas') + (float) $units->sum('penyusutan');
            $margin = round($pendapatan - $biaya, 2);

            return [
                'project'    => $p,
                'pendapatan' => $pendapatan,
                'biaya_kas'  => (float) $units->sum('biaya_kas'),
                'penyusutan' => (float) $units->sum('penyusutan'),
                'biaya'      => $biaya,
                'margin'     => $margin,
                'pct'        => $pendapatan > 0 ? round($margin / $pendapatan * 100, 1) : null,
                'units'      => $units,
            ];
        })->values();

        $totals = [
            'pendapatan' => (float) $rows->sum('pendapatan'),
            'biaya'      => (float) $rows->sum('biaya'),
            'margin'     => (float) $rows->sum('margin'),
        ];
        $totals['pct'] = $totals['pendapatan'] > 0 ? round($totals['margin'] / $totals['pendapatan'] * 100, 1) : 0.0;

        return ['rows' => $rows, 'totals' => $totals];
    }
}
