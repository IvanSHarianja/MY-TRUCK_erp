<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\ArmadaContract;
use App\Models\Company;
use App\Models\Material;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregator data operasional untuk Dashboard Operasional.
 *
 * Fokus: potensi & tindakan (bukan cuma angka pasif).
 * Semua method scoped per tenant company_id.
 */
class OperationalInsightService
{
    /**
     * 4 stat kartu di atas dashboard.
     *
     * @return array{
     *     jam_bulan_ini: float, jam_bulan_lalu: float,
     *     rit_bulan_ini: int, rit_bulan_lalu: int,
     *     unbilled_potential: int,
     *     aset_aktif: int, aset_idle: int,
     * }
     */
    public function stats(int $companyId, ?Carbon $asOf = null): array
    {
        $asOf   = $asOf ?? Carbon::today();
        $thisMonth = $asOf->copy()->startOfMonth();
        $lastMonth = $asOf->copy()->subMonthNoOverflow()->startOfMonth();

        $jamThis = (float) RentalLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereYear('log_date', $thisMonth->year)
            ->whereMonth('log_date', $thisMonth->month)
            ->sum('jam_kerja');

        $jamLast = (float) RentalLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereYear('log_date', $lastMonth->year)
            ->whereMonth('log_date', $lastMonth->month)
            ->sum('jam_kerja');

        $ritThis = (int) RitLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereYear('log_date', $thisMonth->year)
            ->whereMonth('log_date', $thisMonth->month)
            ->sum('rit_count');

        $ritLast = (int) RitLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereYear('log_date', $lastMonth->year)
            ->whereMonth('log_date', $lastMonth->month)
            ->sum('rit_count');

        return [
            'jam_bulan_ini'       => $jamThis,
            'jam_bulan_lalu'      => $jamLast,
            'rit_bulan_ini'       => $ritThis,
            'rit_bulan_lalu'      => $ritLast,
            'unbilled_potential'  => $this->unbilledPotentialTotal($companyId),
            'aset_aktif'          => $this->assetsActiveCount($companyId, $asOf),
            'aset_idle'           => $this->assetsIdleCount($companyId, $asOf),
        ];
    }

    /**
     * Utilization per aset bulan ini — jam kerja actual vs target.
     * Target didefinisikan sebagai (useful_life_hours / 60 bulan) atau default 200 jam.
     *
     * @return array<int, array{
     *     asset_id: int, asset_code: string, name: string, type: string,
     *     jam_actual: float, jam_target: float, utilization_pct: float, status: string,
     * }>
     */
    public function utilizationRate(int $companyId, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::today();
        $month = $asOf->copy()->startOfMonth();

        $assets = Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'aktif')
            ->orderBy('asset_code')
            ->get();

        $rows = [];
        foreach ($assets as $asset) {
            $jamActual = (float) RentalLog::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('asset_id', $asset->id)
                ->whereYear('log_date', $month->year)
                ->whereMonth('log_date', $month->month)
                ->sum('jam_kerja');

            // Priority target:
            // 1. Explicit monthly_target_hours di form aset (owner set manual)
            // 2. Fallback: useful_life_hours / 60 (distribusi rata umur ekonomis)
            // 3. Default: 200 jam/bulan (rata-rata sewa aktif ~1 shift × 25 hari)
            $target = $asset->monthly_target_hours
                ? (float) $asset->monthly_target_hours
                : ($asset->useful_life_hours
                    ? round((float) $asset->useful_life_hours / 60, 2)
                    : 200.0);

            $pct = $target > 0 ? round(($jamActual / $target) * 100, 1) : 0;
            $status = match (true) {
                $pct >= 80 => 'peak',       // hijau — potensi add aset
                $pct >= 40 => 'normal',
                $pct > 0   => 'low',        // kuning
                default    => 'idle',       // merah
            };

            $rows[] = [
                'asset_id'        => $asset->id,
                'asset_code'      => $asset->asset_code,
                'name'            => $asset->name,
                'type'            => $asset->type,
                'jam_actual'      => $jamActual,
                'jam_target'      => $target,
                'utilization_pct' => $pct,
                'status'          => $status,
            ];
        }

        // Sort ascending (yang paling kurang dipakai di atas — priority action)
        usort($rows, fn ($a, $b) => $a['utilization_pct'] <=> $b['utilization_pct']);

        return $rows;
    }

    /**
     * Log operasional yang belum ditagih — potensi revenue.
     *
     * @return array<int, array{
     *     contract_type: string, contract_number: string, contract_id: int,
     *     client_name: string, asset_code: string,
     *     unbilled_qty: float, unit: string, estimated_value: int,
     *     oldest_log_date: string, oldest_days_ago: int,
     * }>
     */
    public function unbilledLogs(int $companyId): array
    {
        $rows = [];

        // Rental logs unbilled
        $rentalGroups = RentalLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('invoice_id')
            ->where('jam_kerja', '>', 0)
            ->selectRaw('rental_contract_id, SUM(jam_kerja) as total_jam, MIN(log_date) as oldest_date, COUNT(*) as log_count')
            ->groupBy('rental_contract_id')
            ->get();

        foreach ($rentalGroups as $g) {
            $contract = RentalContract::withoutGlobalScopes()
                ->with(['client', 'asset'])
                ->find($g->rental_contract_id);
            if (! $contract) continue;

            $rows[] = [
                'contract_type'   => 'RENT',
                'contract_number' => $contract->contract_number,
                'contract_id'     => $contract->id,
                'client_name'     => optional($contract->client)->name ?? '—',
                'asset_code'      => optional($contract->asset)->asset_code ?? '—',
                'unbilled_qty'    => (float) $g->total_jam,
                'unit'            => 'jam',
                'estimated_value' => (int) round((float) $g->total_jam * (float) $contract->tarif_per_jam),
                'oldest_log_date' => $g->oldest_date,
                'oldest_days_ago' => (int) Carbon::parse($g->oldest_date)->diffInDays(Carbon::today()),
            ];
        }

        // Rit logs unbilled
        $ritGroups = RitLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('invoice_id')
            ->where('rit_count', '>', 0)
            ->selectRaw('armada_contract_id, SUM(rit_count) as total_rit, MIN(log_date) as oldest_date, COUNT(*) as log_count')
            ->groupBy('armada_contract_id')
            ->get();

        foreach ($ritGroups as $g) {
            $contract = ArmadaContract::withoutGlobalScopes()
                ->with(['client'])
                ->find($g->armada_contract_id);
            if (! $contract) continue;

            $rows[] = [
                'contract_type'   => 'ARMD',
                'contract_number' => $contract->contract_number,
                'contract_id'     => $contract->id,
                'client_name'     => optional($contract->client)->name ?? '—',
                'asset_code'      => '—',
                'unbilled_qty'    => (int) $g->total_rit,
                'unit'            => 'rit',
                'estimated_value' => (int) round((int) $g->total_rit * (float) $contract->tarif_per_rit),
                'oldest_log_date' => $g->oldest_date,
                'oldest_days_ago' => (int) Carbon::parse($g->oldest_date)->diffInDays(Carbon::today()),
            ];
        }

        // Sort by umur descending (yang paling lama belum ditagih di atas)
        usort($rows, fn ($a, $b) => $b['oldest_days_ago'] <=> $a['oldest_days_ago']);

        return $rows;
    }

    public function unbilledPotentialTotal(int $companyId): int
    {
        return array_sum(array_column($this->unbilledLogs($companyId), 'estimated_value'));
    }

    /**
     * Material dengan stok kritis (perlu re-order).
     *
     * @return array<int, array{
     *     material_id: int, code: string, name: string, satuan: string,
     *     current_stock: float, current_mac: float, avg_consumption_30d: float, days_left: float|null,
     * }>
     */
    public function criticalStock(int $companyId, float $criticalThreshold = 5.0): array
    {
        $materials = Material::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            // Filter: material yang PERNAH ada movement (bukan legacy tanpa purchase)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('material_stock_movements')
                    ->whereColumn('material_stock_movements.material_id', 'materials.id');
            })
            ->where('current_stock', '<', $criticalThreshold * 2) // ambil yang mendekati kritis
            ->orderBy('current_stock')
            ->get();

        $rows = [];
        foreach ($materials as $m) {
            // Rata-rata konsumsi 30 hari terakhir
            $consumption30d = (float) DB::table('material_stock_movements')
                ->where('material_id', $m->id)
                ->where('movement_type', 'out')
                ->where('movement_date', '>=', Carbon::today()->subDays(30)->toDateString())
                ->sum(DB::raw('ABS(qty_change)'));

            $avgDaily = $consumption30d / 30;
            $daysLeft = $avgDaily > 0
                ? round((float) $m->current_stock / $avgDaily, 1)
                : null;

            $rows[] = [
                'material_id'         => $m->id,
                'code'                => $m->code,
                'name'                => $m->name,
                'satuan'              => $m->satuan,
                'current_stock'       => (float) $m->current_stock,
                'current_mac'         => (float) $m->current_mac,
                'avg_consumption_30d' => round($avgDaily, 2),
                'days_left'           => $daysLeft,
            ];
        }

        return $rows;
    }

    private function assetsActiveCount(int $companyId, Carbon $asOf): int
    {
        // Aset aktif = punya minimal 1 log dalam 7 hari terakhir
        $recentThreshold = $asOf->copy()->subDays(7)->toDateString();

        return (int) Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'aktif')
            ->where(function ($q) use ($recentThreshold) {
                $q->whereExists(function ($sub) use ($recentThreshold) {
                    $sub->select(DB::raw(1))
                        ->from('rental_logs')
                        ->whereColumn('rental_logs.asset_id', 'assets.id')
                        ->where('log_date', '>=', $recentThreshold);
                })->orWhereExists(function ($sub) use ($recentThreshold) {
                    $sub->select(DB::raw(1))
                        ->from('rit_logs')
                        ->whereColumn('rit_logs.asset_id', 'assets.id')
                        ->where('log_date', '>=', $recentThreshold);
                });
            })
            ->count();
    }

    private function assetsIdleCount(int $companyId, Carbon $asOf): int
    {
        // Aset idle = status aktif TAPI tidak ada log 7 hari terakhir
        $recentThreshold = $asOf->copy()->subDays(7)->toDateString();

        return (int) Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'aktif')
            ->whereNotExists(function ($sub) use ($recentThreshold) {
                $sub->select(DB::raw(1))
                    ->from('rental_logs')
                    ->whereColumn('rental_logs.asset_id', 'assets.id')
                    ->where('log_date', '>=', $recentThreshold);
            })
            ->whereNotExists(function ($sub) use ($recentThreshold) {
                $sub->select(DB::raw(1))
                    ->from('rit_logs')
                    ->whereColumn('rit_logs.asset_id', 'assets.id')
                    ->where('log_date', '>=', $recentThreshold);
            })
            ->count();
    }
}
