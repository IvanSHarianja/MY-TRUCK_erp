<?php

namespace App\Enums;

/**
 * Tipe pemeliharaan aset (dump truck, alat berat, kendaraan operasional,
 * peralatan kantor).
 *
 * Business decision A3 disetujui 2026-07-06: pakai enum fix (bukan master
 * table customizable). Kalau granularity perlu di-tambah nanti, cukup edit
 * enum di sini + migration modify column.
 */
enum MaintenanceType: string
{
    case ServiceRutin       = 'service_rutin';
    case ServiceBerat       = 'service_berat';
    case GantiSparepart     = 'ganti_sparepart';
    case PerbaikanDarurat   = 'perbaikan_darurat';
    case Inspeksi           = 'inspeksi';

    public function label(): string
    {
        return match ($this) {
            self::ServiceRutin     => 'Service Rutin',
            self::ServiceBerat     => 'Service Berat / Overhaul',
            self::GantiSparepart   => 'Ganti Sparepart',
            self::PerbaikanDarurat => 'Perbaikan Darurat (Breakdown)',
            self::Inspeksi         => 'Inspeksi / Pemeriksaan',
        };
    }

    /**
     * Icon Heroicon untuk visualisasi di Filament table & form.
     */
    public function icon(): string
    {
        return match ($this) {
            self::ServiceRutin     => 'heroicon-o-wrench-screwdriver',
            self::ServiceBerat     => 'heroicon-o-cog-8-tooth',
            self::GantiSparepart   => 'heroicon-o-puzzle-piece',
            self::PerbaikanDarurat => 'heroicon-o-exclamation-triangle',
            self::Inspeksi         => 'heroicon-o-clipboard-document-check',
        };
    }

    /**
     * Warna badge di UI (Filament color).
     */
    public function color(): string
    {
        return match ($this) {
            self::ServiceRutin     => 'info',
            self::ServiceBerat     => 'warning',
            self::GantiSparepart   => 'gray',
            self::PerbaikanDarurat => 'danger',
            self::Inspeksi         => 'success',
        };
    }

    /**
     * Preventive vs corrective — untuk analisis kualitas maintenance.
     * Rasio corrective tinggi = tanda armada boros / perlu ganti unit.
     */
    public function isPreventive(): bool
    {
        return match ($this) {
            self::ServiceRutin, self::Inspeksi => true,
            default                             => false,
        };
    }
}
