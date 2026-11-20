<?php

namespace App\Services\Accounting;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\RentalLog;
use App\Models\RitLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Biaya Standar vs Aktual per unit, satu bulan.
 *
 *  - Standar = tarif biaya di kontrak × pemakaian (rit / jam) dari log harian
 *    (OperationalCostService — angka yang dulu dijurnal otomatis).
 *  - Aktual  = beban yang benar-benar dicatat keuangan dan ditandai unitnya
 *    (journal_entry_lines.asset_id), posted, bukan pembalik. Jurnal lama
 *    BBK-RT / BBK-RL (biaya standar) TIDAK dihitung sebagai aktual — totalnya
 *    ditampilkan terpisah supaya bisa di-void bila biaya aktualnya sudah dicatat.
 *
 * Komponen: BBM, Gaji & makan, Uang jalan & premi. Keputusan 2026-11-20:
 * Laba Rugi memakai biaya aktual; tarif kontrak hanya standar pembanding.
 */
class StandardVsActualCostService
{
    public const COMPONENTS = [
        'bbm'        => 'BBM',
        'gaji'       => 'Gaji & Makan',
        'uang_jalan' => 'Uang Jalan & Premi',
    ];

    public function __construct(private OperationalCostService $costService) {}

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   totals: array{standar: array<string, float>, aktual: array<string, float>},
     *   untagged: float,
     *   legacy: array{count: int, total: float},
     * }
     */
    public function build(int $companyId, int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to   = $from->copy()->endOfMonth();

        $empty = fn (): array => array_fill_keys(array_keys(self::COMPONENTS), 0.0);
        $rows  = [];
        $row   = function (int $assetId) use (&$rows, $empty): void {
            $rows[$assetId] ??= ['standar' => $empty(), 'aktual' => $empty(), 'kontrak' => [], 'pemakaian' => []];
        };

        // --- Standar dari log ---
        $ritLogs = RitLog::withoutGlobalScopes()->with('armadaContract')
            ->where('company_id', $companyId)
            ->whereDate('log_date', '>=', $from->toDateString())
            ->whereDate('log_date', '<=', $to->toDateString())
            ->whereNotNull('asset_id')
            ->get();
        foreach ($ritLogs as $log) {
            $c = $this->costService->computeRitLogCost($log);
            $row($log->asset_id);
            $rows[$log->asset_id]['standar']['bbm']        += $c['bbm'];
            $rows[$log->asset_id]['standar']['gaji']       += $c['gaji'] + $c['makan'];
            $rows[$log->asset_id]['standar']['uang_jalan'] += $c['uang_jalan'] + $c['premi'];
            $rows[$log->asset_id]['pemakaian']['rit'] = ($rows[$log->asset_id]['pemakaian']['rit'] ?? 0) + (float) $log->rit_count;
            if ($log->armadaContract) {
                $rows[$log->asset_id]['kontrak'][$log->armadaContract->contract_number] = true;
            }
        }

        $rentalLogs = RentalLog::withoutGlobalScopes()->with('rentalContract')
            ->where('company_id', $companyId)
            ->whereDate('log_date', '>=', $from->toDateString())
            ->whereDate('log_date', '<=', $to->toDateString())
            ->whereNotNull('asset_id')
            ->get();
        foreach ($rentalLogs as $log) {
            $c = $this->costService->computeRentalLogCost($log);
            $row($log->asset_id);
            $rows[$log->asset_id]['standar']['bbm']        += $c['bbm'];
            $rows[$log->asset_id]['standar']['gaji']       += $c['gaji'] + $c['makan'];
            $rows[$log->asset_id]['standar']['uang_jalan'] += $c['premi'];
            $rows[$log->asset_id]['pemakaian']['jam'] = ($rows[$log->asset_id]['pemakaian']['jam'] ?? 0) + (float) $log->jam_kerja;
            if ($log->rentalContract) {
                $rows[$log->asset_id]['kontrak'][$log->rentalContract->contract_number] = true;
            }
        }

        // --- Aktual dari jurnal ---
        $accounts = $this->componentAccounts($companyId);
        $allIds   = array_merge(...array_values($accounts));

        $base = fn () => DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereNotIn('je.document_type', ['pembalik', 'penutup'])
            // Periode jurnal, sama dengan Laba Rugi (jurnal penyesuaian ber-tanggal lain ikut bulannya).
            ->where('je.period_year', $year)
            ->where('je.period_month', $month)
            ->whereIn('jl.account_id', $allIds ?: [0]);

        $legacyFilter = fn ($q) => $q->where('je.document_number', 'like', 'BBK-RT-%')
            ->orWhere('je.document_number', 'like', 'BBK-RL-%');

        $actual = $base()
            ->whereNotNull('jl.asset_id')
            ->where(fn ($q) => $q->whereNull('je.document_number')->orWhereNot($legacyFilter))
            ->selectRaw('jl.asset_id, jl.account_id, SUM(jl.debit - jl.kredit) as amount')
            ->groupBy('jl.asset_id', 'jl.account_id')
            ->get();
        foreach ($actual as $a) {
            $component = $this->componentOf((int) $a->account_id, $accounts);
            if (! $component) {
                continue;
            }
            $row((int) $a->asset_id);
            $rows[(int) $a->asset_id]['aktual'][$component] += (float) $a->amount;
        }

        // Biaya lini Armada / Rental yang belum ditandai unit (gaji kantor di UMUM tidak dihitung).
        $untagged = (float) $base()
            ->join('business_units as bu', 'bu.id', '=', 'je.business_unit_id')
            ->whereIn('bu.code', ['ARMD', 'RENT'])
            ->whereNull('jl.asset_id')
            ->where(fn ($q) => $q->whereNull('je.document_number')->orWhereNot($legacyFilter))
            ->sum(DB::raw('jl.debit - jl.kredit'));

        $legacy = $base()->where($legacyFilter)
            ->selectRaw('COUNT(DISTINCT je.id) as c, SUM(jl.debit - jl.kredit) as t')
            ->first();

        // --- Susun ---
        $assets = Asset::withoutGlobalScopes()->whereIn('id', array_keys($rows))->get()->keyBy('id');
        $totals = ['standar' => $empty(), 'aktual' => $empty()];
        $out = [];
        foreach ($rows as $assetId => $r) {
            foreach (['standar', 'aktual'] as $k) {
                $r[$k] = array_map(fn ($v) => round($v, 2), $r[$k]);
                foreach ($r[$k] as $comp => $v) {
                    $totals[$k][$comp] += $v;
                }
            }
            $std = array_sum($r['standar']);
            $act = array_sum($r['aktual']);
            $asset = $assets->get($assetId);
            $out[] = [
                'asset_id'      => $assetId,
                'asset_code'    => $asset?->asset_code ?? '#' . $assetId,
                'name'          => $asset?->name,
                'kontrak'       => array_keys($r['kontrak']),
                'pemakaian'     => $r['pemakaian'],
                'standar'       => $r['standar'],
                'aktual'        => $r['aktual'],
                'total_standar' => round($std, 2),
                'total_aktual'  => round($act, 2),
                'selisih'       => round($act - $std, 2),
                'selisih_pct'   => $std > 0 ? round(($act - $std) / $std * 100, 1) : null,
            ];
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['asset_code'], (string) $b['asset_code']));

        return [
            'rows'     => $out,
            'totals'   => $totals,
            'untagged' => round($untagged, 2),
            'legacy'   => ['count' => (int) ($legacy->c ?? 0), 'total' => round((float) ($legacy->t ?? 0), 2)],
        ];
    }

    /** @return array<string, array<int>> komponen → id akun beban */
    private function componentAccounts(int $companyId): array
    {
        $ids = function (AccountRole $role, string $code) use ($companyId): array {
            $byRole = Account::idsByRole($role, $companyId);
            $byCode = Account::findPostableByCode($code, $companyId)?->id;

            return array_values(array_unique(array_filter([...$byRole, $byCode])));
        };

        return [
            'bbm'        => $ids(AccountRole::CogsBbm, '551100'),
            'gaji'       => $ids(AccountRole::OpexGaji, '552200'),
            'uang_jalan' => $ids(AccountRole::CogsPremiUangJalan, '551200'),
        ];
    }

    private function componentOf(int $accountId, array $accounts): ?string
    {
        foreach ($accounts as $component => $ids) {
            if (in_array($accountId, $ids, true)) {
                return $component;
            }
        }

        return null;
    }
}
