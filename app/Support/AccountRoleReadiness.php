<?php

namespace App\Support;

use App\Enums\AccountRole;
use App\Models\Account;

/**
 * Peran akun yang dibutuhkan jurnal otomatis tiap fitur, dan mana yang belum
 * ada di COA sebuah PT.
 *
 * Penting untuk PT dengan COA hasil import: kodenya bukan kode standar
 * MY-TRUCK, jadi fitur hanya menemukan akun lewat PERAN. Peran yang hilang =
 * fitur gagal membuat jurnal (mis. tagih kontrak armada tanpa akun
 * "Pendapatan Ritase"). Daftar ini mengikuti Account::findByRoleOrCode() di
 * service & observer terkait — sama dengan panduan "Paket Akun per Fitur".
 */
final class AccountRoleReadiness
{
    /** fitur → peran wajib */
    public const FEATURES = [
        'Inti (invoice & pembayaran)' => [AccountRole::Cash, AccountRole::ReceivableUsaha],
        'Kontrak Armada'              => [AccountRole::RevenueArmd, AccountRole::CogsBbm, AccountRole::OpexGaji, AccountRole::CogsPremiUangJalan],
        'Rental Alat Berat'           => [AccountRole::RevenueRent, AccountRole::CogsBbm, AccountRole::OpexGaji, AccountRole::CogsPremiUangJalan],
        'Material'                    => [AccountRole::RevenueMatl, AccountRole::InventoryMaterial, AccountRole::CogsMaterial],
        'Proyek Borongan'             => [AccountRole::RevenueBong, AccountRole::UangMukaProyek],
        'Pemeliharaan Unit'           => [AccountRole::CogsMaintenance],
        'Pembelian kredit'            => [AccountRole::PayableVendor],
        'Penyusutan'                  => [AccountRole::OpexPenyusutan, AccountRole::AkumulasiArmada],
    ];

    /**
     * @return array<string, list<string>>  fitur → label peran yang belum ada (fitur lengkap tidak dicantumkan)
     */
    public static function missing(int $companyId): array
    {
        $codes = array_flip(AccountRole::standardCodeMapping());   // role → kode standar (fallback)
        $found = [];
        $result = [];

        foreach (self::FEATURES as $feature => $roles) {
            foreach ($roles as $role) {
                // Cara mencari = cara fitur mencari akun saat menjurnal.
                $found[$role->value] ??= Account::findByRoleOrCode($role, $codes[$role->value] ?? '', $companyId) !== null;

                if (! $found[$role->value]) {
                    $result[$feature][] = $role->label();
                }
            }
        }

        return $result;
    }

    /** Ringkasan satu baris untuk notifikasi/subjudul. */
    public static function summary(int $companyId): ?string
    {
        $missing = self::missing($companyId);
        if ($missing === []) {
            return null;
        }

        return 'Peran akun belum lengkap — fitur berikut belum bisa membuat jurnal otomatis: '
            . collect($missing)->map(fn ($roles, $f) => "{$f} (" . implode(', ', $roles) . ')')->implode('; ')
            . '. Isi Peran di akun yang sesuai (lihat Panduan → Paket Akun per Fitur).';
    }
}
