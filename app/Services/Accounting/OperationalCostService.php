<?php

namespace App\Services\Accounting;

use App\Models\ArmadaContract;
use App\Models\Company;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;

/**
 * Kalkulasi biaya operasional harian untuk RentalLog (RENT) dan RitLog (ARMD).
 *
 * Skema HYBRID:
 *   - Kontrak menyimpan STANDAR biaya (bbm/jam, gaji/hari, dll).
 *   - Log harian input MINIMAL (jam_kerja atau rit_count).
 *   - Service hitung otomatis: komponen biaya = standar × unit_pemakaian.
 *   - Kalau override_biaya=true, service pakai nilai yang tersimpan di log.
 *
 * Design:
 *   - Method calculate*() adalah pure function (accept array, return array).
 *     Dipakai baik oleh Observer (Tahap 3) maupun UI preview (Tahap 2).
 *   - Method compute*() adalah wrapper yang extract params dari model.
 *   - Resolusi harga_bbm: log.override > contract > company default.
 */
class OperationalCostService
{
    /**
     * Kalkulasi cost RentalLog dari raw params. Pure function — tidak
     * menyentuh DB, aman dipanggil di form preview.
     *
     * @param array{
     *     jam_kerja: float|int|string|null,
     *     override_biaya: bool,
     *     include_bbm: bool,
     *     include_operator: bool,
     *     bbm_liter_per_jam: float|int|string|null,
     *     harga_bbm_per_liter: float|int|string|null,
     *     gaji_operator_per_hari: float|int|string|null,
     *     uang_makan_per_hari: float|int|string|null,
     *     premi_per_jam: float|int|string|null,
     *     override_solar_liter?: float|int|string|null,
     *     override_uang_makan?: float|int|string|null,
     *     override_premi?: float|int|string|null,
     * } $p
     *
     * @return array{bbm: float, gaji: float, makan: float, premi: float, total: float}
     */
    public function calculateRentalCost(array $p): array
    {
        $jam        = (float) ($p['jam_kerja'] ?? 0);
        $override   = (bool)  ($p['override_biaya'] ?? false);
        $includeBbm = (bool)  ($p['include_bbm'] ?? false);
        $includeOp  = (bool)  ($p['include_operator'] ?? false);

        $bbm = 0.0;
        $gaji = 0.0;
        $makan = 0.0;
        $premi = 0.0;

        // BBM — kalau include & jam > 0
        if ($includeBbm && $jam > 0) {
            if ($override) {
                $liter    = (float) ($p['override_solar_liter'] ?? 0);
                $hargaBbm = (float) ($p['harga_bbm_per_liter'] ?? 0);
                $bbm = round($liter * $hargaBbm, 2);
            } else {
                $literPerJam = (float) ($p['bbm_liter_per_jam'] ?? 0);
                $hargaBbm    = (float) ($p['harga_bbm_per_liter'] ?? 0);
                $bbm = round($jam * $literPerJam * $hargaBbm, 2);
            }
        }

        // Gaji operator — flat per hari kerja (bila include_operator & jam > 0)
        if ($includeOp && $jam > 0) {
            $gaji = round((float) ($p['gaji_operator_per_hari'] ?? 0), 2);
        }

        // Uang makan — flat per hari kerja
        if ($includeOp && $jam > 0) {
            $makan = $override
                ? round((float) ($p['override_uang_makan'] ?? 0), 2)
                : round((float) ($p['uang_makan_per_hari'] ?? 0), 2);
        }

        // Premi — per jam × jam kerja (opsional)
        if ($jam > 0) {
            if ($override) {
                $premi = round((float) ($p['override_premi'] ?? 0), 2);
            } else {
                $premiPerJam = (float) ($p['premi_per_jam'] ?? 0);
                if ($premiPerJam > 0) {
                    $premi = round($jam * $premiPerJam, 2);
                }
            }
        }

        return [
            'bbm'   => $bbm,
            'gaji'  => $gaji,
            'makan' => $makan,
            'premi' => $premi,
            'total' => round($bbm + $gaji + $makan + $premi, 2),
        ];
    }

    /**
     * Kalkulasi cost RitLog (ARMD). Basis per rit + per hari kerja.
     *
     * @param array{
     *     rit_count: int|string|null,
     *     override_biaya: bool,
     *     include_bbm: bool,
     *     include_operator: bool,
     *     bbm_liter_per_rit: float|int|string|null,
     *     harga_bbm_per_liter: float|int|string|null,
     *     gaji_supir_per_hari: float|int|string|null,
     *     uang_makan_per_hari: float|int|string|null,
     *     uang_jalan_per_rit: float|int|string|null,
     *     premi_per_rit: float|int|string|null,
     *     override_solar_liter?: float|int|string|null,
     *     override_uang_jalan?: float|int|string|null,
     *     override_uang_makan?: float|int|string|null,
     *     override_premi?: float|int|string|null,
     * } $p
     *
     * @return array{bbm: float, gaji: float, makan: float, uang_jalan: float, premi: float, total: float}
     */
    public function calculateRitCost(array $p): array
    {
        $rit        = (int)  ($p['rit_count'] ?? 0);
        $override   = (bool) ($p['override_biaya'] ?? false);
        $includeBbm = (bool) ($p['include_bbm'] ?? false);
        $includeOp  = (bool) ($p['include_operator'] ?? false);

        $bbm = 0.0;
        $gaji = 0.0;
        $makan = 0.0;
        $uangJalan = 0.0;
        $premi = 0.0;

        if ($includeBbm && $rit > 0) {
            if ($override) {
                $liter    = (float) ($p['override_solar_liter'] ?? 0);
                $hargaBbm = (float) ($p['harga_bbm_per_liter'] ?? 0);
                $bbm = round($liter * $hargaBbm, 2);
            } else {
                $literPerRit = (float) ($p['bbm_liter_per_rit'] ?? 0);
                $hargaBbm    = (float) ($p['harga_bbm_per_liter'] ?? 0);
                $bbm = round($rit * $literPerRit * $hargaBbm, 2);
            }
        }

        if ($includeOp && $rit > 0) {
            $gaji = round((float) ($p['gaji_supir_per_hari'] ?? 0), 2);

            $makan = $override
                ? round((float) ($p['override_uang_makan'] ?? 0), 2)
                : round((float) ($p['uang_makan_per_hari'] ?? 0), 2);

            $uangJalan = $override
                ? round((float) ($p['override_uang_jalan'] ?? 0), 2)
                : round((float) ($p['uang_jalan_per_rit'] ?? 0) * $rit, 2);
        }

        if ($rit > 0) {
            if ($override) {
                $premi = round((float) ($p['override_premi'] ?? 0), 2);
            } else {
                $premiPerRit = (float) ($p['premi_per_rit'] ?? 0);
                if ($premiPerRit > 0) {
                    $premi = round($rit * $premiPerRit, 2);
                }
            }
        }

        return [
            'bbm'        => $bbm,
            'gaji'       => $gaji,
            'makan'      => $makan,
            'uang_jalan' => $uangJalan,
            'premi'      => $premi,
            'total'      => round($bbm + $gaji + $makan + $uangJalan + $premi, 2),
        ];
    }

    /**
     * Wrapper: hitung cost dari RentalLog model.
     * Resolve params dari log + parent contract + company default.
     */
    public function computeRentalLogCost(RentalLog $log): array
    {
        $contract = $log->rentalContract;
        if (! $contract) {
            return $this->emptyRental();
        }

        $company = Company::withoutGlobalScopes()->find($log->company_id);
        $hargaBbm = $this->resolveHargaBbm($contract->harga_bbm_per_liter, $company);

        return $this->calculateRentalCost([
            'jam_kerja'              => $log->jam_kerja,
            'override_biaya'         => (bool) $log->override_biaya,
            'include_bbm'            => (bool) $contract->include_bbm,
            'include_operator'       => (bool) $contract->include_operator,
            'bbm_liter_per_jam'      => $contract->bbm_liter_per_jam,
            'harga_bbm_per_liter'    => $hargaBbm,
            'gaji_operator_per_hari' => $contract->gaji_operator_per_hari,
            'uang_makan_per_hari'    => $contract->uang_makan_per_hari,
            'premi_per_jam'          => $contract->premi_per_jam,
            'override_solar_liter'   => $log->solar_liter,
            'override_uang_makan'    => $log->uang_makan_operator,
            'override_premi'         => $log->premi_operator,
        ]);
    }

    /**
     * Wrapper: hitung cost dari RitLog model.
     */
    public function computeRitLogCost(RitLog $log): array
    {
        $contract = $log->armadaContract;
        if (! $contract) {
            return $this->emptyRit();
        }

        $company = Company::withoutGlobalScopes()->find($log->company_id);
        $hargaBbm = $this->resolveHargaBbm($contract->harga_bbm_per_liter, $company);

        return $this->calculateRitCost([
            'rit_count'           => $log->rit_count,
            'override_biaya'      => (bool) $log->override_biaya,
            'include_bbm'         => (bool) $contract->include_bbm,
            'include_operator'    => (bool) $contract->include_operator,
            'bbm_liter_per_rit'   => $contract->bbm_liter_per_rit,
            'harga_bbm_per_liter' => $hargaBbm,
            'gaji_supir_per_hari' => $contract->gaji_supir_per_hari,
            'uang_makan_per_hari' => $contract->uang_makan_per_hari,
            'uang_jalan_per_rit'  => $contract->uang_jalan_per_rit,
            'premi_per_rit'       => $contract->premi_per_rit,
            'override_solar_liter'=> $log->solar_liter,
            'override_uang_jalan' => $log->uang_jalan_supir,
            'override_uang_makan' => $log->uang_makan_supir,
            'override_premi'      => $log->premi_supir,
        ]);
    }

    /**
     * Resolusi harga BBM: contract > company default.
     * Log tidak override harga (hanya liter yang bisa di-override — harga
     * disamakan supaya laporan konsisten).
     */
    public function resolveHargaBbm(mixed $contractHarga, ?Company $company): float
    {
        if ($contractHarga !== null && (float) $contractHarga > 0) {
            return (float) $contractHarga;
        }

        return (float) ($company?->harga_solar_default ?? 6800);
    }

    /** @return array{bbm: float, gaji: float, makan: float, premi: float, total: float} */
    private function emptyRental(): array
    {
        return ['bbm' => 0.0, 'gaji' => 0.0, 'makan' => 0.0, 'premi' => 0.0, 'total' => 0.0];
    }

    /** @return array{bbm: float, gaji: float, makan: float, uang_jalan: float, premi: float, total: float} */
    private function emptyRit(): array
    {
        return ['bbm' => 0.0, 'gaji' => 0.0, 'makan' => 0.0, 'uang_jalan' => 0.0, 'premi' => 0.0, 'total' => 0.0];
    }
}
