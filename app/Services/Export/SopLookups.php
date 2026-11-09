<?php

namespace App\Services\Export;

/**
 * Nilai MY-TRUCK → teks dropdown di sheet Parameter SOP Armada.
 * Teks HARUS persis sama dengan daftar di template (rumus SOP
 * membandingkan string, mis. IF(K12="All In", ...)).
 */
final class SopLookups
{
    /** Parameter!L7:L36 */
    public const LINI = ['RENT', 'ARMD', 'MATL', 'BONG', 'UMUM'];

    /** assets.type → Parameter!V (Jenis Unit) */
    public const JENIS_UNIT = [
        'dump_truck'            => 'Dump Truck',
        'excavator'             => 'Excavator',
        'bulldozer'             => 'Bulldozer',
        'wheel_loader'          => 'Wheel Loader',
        'kendaraan_operasional' => 'Kendaraan Operasional',
        'peralatan_kantor'      => 'Peralatan Kantor',
        'lainnya'               => 'Lainnya',
    ];

    /** employees.position → Parameter!AE (Posisi Karyawan) */
    public const POSISI = [
        'driver'   => 'Driver',
        'operator' => 'Operator',
        'mandor'   => 'Mandor',
        'admin'    => 'Admin',
        'mekanik'  => 'Mekanik',
        'lainnya'  => 'Lainnya',
    ];

    /** asset_maintenance_logs.type → Parameter!AK (Jenis Pemeliharaan) */
    public const JENIS_PEMELIHARAAN = [
        'service_rutin'     => 'Service Rutin',
        'service_berat'     => 'Service Berat / Overhaul',
        'ganti_sparepart'   => 'Ganti Sparepart',
        'perbaikan_darurat' => 'Perbaikan Darurat (Breakdown)',
        'inspeksi'          => 'Inspeksi / Pemeriksaan',
    ];

    /** tipe_kontrak / tipe_rental → Parameter!S (Tipe Kontrak) */
    public const TIPE_KONTRAK = [
        'all_in'    => 'All In',
        'semi'      => 'Semi',
        'alat_saja' => 'Alat Saja',
    ];

    /** status kontrak → Parameter!P */
    public const STATUS_KONTRAK = [
        'aktif'   => 'Aktif',
        'selesai' => 'Selesai',
        'batal'   => 'Batal',
    ];

    /** projects.status → Parameter!AZ */
    public const STATUS_PROYEK = [
        'berjalan' => 'Berjalan',
        'selesai'  => 'Selesai',
        'batal'    => 'Batal',
    ];

    /** vendors.type → Parameter!CD (Jenis Vendor) */
    public const JENIS_VENDOR = [
        'kuari'     => 'Material',
        'bbm'       => 'BBM',
        'sparepart' => 'Sparepart',
    ];

    /**
     * Data di MY-TRUCK tidak punya alur persetujuan terpisah: data yang
     * sudah tersimpan = sah. Kolom Approval SOP diisi ini supaya rumus
     * peringatan tidak menandai semua baris "belum disetujui".
     */
    public const APPROVED = 'Disetujui';

    /** Kode angka murni ditulis sebagai angka, seperti template. */
    public static function code(?string $code): int|string|null
    {
        if ($code === null || $code === '') {
            return null;
        }

        return preg_match('/^[1-9]\d{0,14}$/', $code) ? (int) $code : $code;
    }
}
