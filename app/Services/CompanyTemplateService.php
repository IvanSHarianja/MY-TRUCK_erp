<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Material;
use Illuminate\Support\Facades\DB;

class CompanyTemplateService
{
    public function seedDefaults(Company $company): void
    {
        DB::transaction(function () use ($company) {
            $this->seedAccounts($company);
            $this->seedBusinessUnits($company);
            $this->seedMaterials($company);
        });
    }

    public function seedAccounts(Company $company): void
    {
        foreach ($this->accounts() as $row) {
            Account::updateOrCreate(
                ['company_id' => $company->id, 'code' => $row['code']],
                $row + ['company_id' => $company->id, 'is_active' => true],
            );
        }
    }

    public function seedBusinessUnits(Company $company): void
    {
        foreach ($this->businessUnits() as $row) {
            BusinessUnit::updateOrCreate(
                ['company_id' => $company->id, 'code' => $row['code']],
                $row + ['company_id' => $company->id, 'is_active' => true],
            );
        }
    }

    public function seedMaterials(Company $company): void
    {
        foreach ($this->materials() as $row) {
            Material::updateOrCreate(
                ['company_id' => $company->id, 'code' => $row['code']],
                $row + ['company_id' => $company->id, 'is_active' => true],
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function accounts(): array
    {
        return [
            // === 1 ASET ===
            ['code' => '111100', 'name' => 'Kas dan Bank',                     'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],
            ['code' => '111110', 'name' => 'Kas Kecil Lapangan',               'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],
            ['code' => '111200', 'name' => 'Piutang Usaha',                    'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],
            ['code' => '111210', 'name' => 'Piutang Retensi',                  'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],
            ['code' => '111220', 'name' => 'Persediaan Solar (Depo)',          'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],
            ['code' => '111230', 'name' => 'Asuransi Dibayar Dimuka',          'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],
            ['code' => '111240', 'name' => 'Perlengkapan Lapangan',            'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],
            ['code' => '111250', 'name' => 'Uang Muka Operasional',            'category' => 'aset', 'sub_category' => 'aset_lancar', 'normal_balance' => 'debit',  'cash_flow_category' => 'operasi',    'tax_type' => 'non_pajak'],

            ['code' => '112100', 'name' => 'Aset Tetap — Armada & Alat Berat',  'category' => 'aset', 'sub_category' => 'aset_tetap', 'normal_balance' => 'debit',  'cash_flow_category' => 'investasi', 'tax_type' => 'non_pajak'],
            ['code' => '112105', 'name' => 'Akumulasi Penyusutan Armada',       'category' => 'aset', 'sub_category' => 'aset_tetap', 'normal_balance' => 'kredit', 'cash_flow_category' => 'investasi', 'tax_type' => 'non_pajak'],
            ['code' => '112110', 'name' => 'Aset Tetap — Peralatan Kantor',     'category' => 'aset', 'sub_category' => 'aset_tetap', 'normal_balance' => 'debit',  'cash_flow_category' => 'investasi', 'tax_type' => 'non_pajak'],
            ['code' => '112115', 'name' => 'Akumulasi Penyusutan Peralatan',    'category' => 'aset', 'sub_category' => 'aset_tetap', 'normal_balance' => 'kredit', 'cash_flow_category' => 'investasi', 'tax_type' => 'non_pajak'],
            ['code' => '112120', 'name' => 'Aset Tetap — Kendaraan Operasional','category' => 'aset', 'sub_category' => 'aset_tetap', 'normal_balance' => 'debit',  'cash_flow_category' => 'investasi', 'tax_type' => 'non_pajak'],
            ['code' => '112125', 'name' => 'Akumulasi Penyusutan Kendaraan Op.','category' => 'aset', 'sub_category' => 'aset_tetap', 'normal_balance' => 'kredit', 'cash_flow_category' => 'investasi', 'tax_type' => 'non_pajak'],
            ['code' => '113100', 'name' => 'Uang Muka Pembelian Aset',          'category' => 'aset', 'sub_category' => 'aset_tetap', 'normal_balance' => 'debit',  'cash_flow_category' => 'investasi', 'tax_type' => 'non_pajak'],

            // === 2 KEWAJIBAN ===
            ['code' => '221100', 'name' => 'Utang Usaha Vendor',                'category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi',   'tax_type' => 'non_pajak'],
            ['code' => '221110', 'name' => 'Utang Usaha Kuari',                 'category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi',   'tax_type' => 'non_pajak'],
            ['code' => '221120', 'name' => 'Utang Gaji Karyawan',               'category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi',   'tax_type' => 'non_pajak'],
            ['code' => '221130', 'name' => 'Utang PPN',                         'category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi',   'tax_type' => 'ppn'],
            ['code' => '221140', 'name' => 'Utang PPh 21 & PPh 23',             'category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi',   'tax_type' => 'pph_21'],
            ['code' => '221150', 'name' => 'Utang Angsuran Leasing (JB < 1thn)','category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'pendanaan', 'tax_type' => 'non_pajak'],
            ['code' => '221160', 'name' => 'Utang Lain-lain Lancar',            'category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi',   'tax_type' => 'non_pajak'],
            ['code' => '221170', 'name' => 'Uang Muka Proyek Diterima',         'category' => 'kewajiban', 'sub_category' => 'kewajiban_lancar',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi',   'tax_type' => 'non_pajak'],

            ['code' => '222100', 'name' => 'Utang Leasing',                     'category' => 'kewajiban', 'sub_category' => 'kewajiban_panjang', 'normal_balance' => 'kredit', 'cash_flow_category' => 'pendanaan', 'tax_type' => 'non_pajak'],
            ['code' => '222110', 'name' => 'Utang Bank',                        'category' => 'kewajiban', 'sub_category' => 'kewajiban_panjang', 'normal_balance' => 'kredit', 'cash_flow_category' => 'pendanaan', 'tax_type' => 'non_pajak'],
            ['code' => '222120', 'name' => 'Utang Pemegang Saham',              'category' => 'kewajiban', 'sub_category' => 'kewajiban_panjang', 'normal_balance' => 'kredit', 'cash_flow_category' => 'pendanaan', 'tax_type' => 'non_pajak'],
            ['code' => '222130', 'name' => 'Utang Jangka Panjang Lainnya',      'category' => 'kewajiban', 'sub_category' => 'kewajiban_panjang', 'normal_balance' => 'kredit', 'cash_flow_category' => 'pendanaan', 'tax_type' => 'non_pajak'],

            // === 3 EKUITAS ===
            ['code' => '331100', 'name' => 'Modal Pemilik / Modal Disetor',     'category' => 'ekuitas', 'sub_category' => 'ekuitas', 'normal_balance' => 'kredit', 'cash_flow_category' => 'non_kas',   'tax_type' => 'non_pajak'],
            ['code' => '331200', 'name' => 'Prive / Pengambilan Modal',         'category' => 'ekuitas', 'sub_category' => 'ekuitas', 'normal_balance' => 'debit',  'cash_flow_category' => 'pendanaan', 'tax_type' => 'non_pajak'],
            ['code' => '331300', 'name' => 'Laba Ditahan (Retained Earnings)',  'category' => 'ekuitas', 'sub_category' => 'ekuitas', 'normal_balance' => 'kredit', 'cash_flow_category' => 'non_kas',   'tax_type' => 'non_pajak'],
            ['code' => '331400', 'name' => 'Laba / Rugi Tahun Berjalan',        'category' => 'ekuitas', 'sub_category' => 'ekuitas', 'normal_balance' => 'kredit', 'cash_flow_category' => 'non_kas',   'tax_type' => 'non_pajak'],

            // === 4 PENDAPATAN ===
            ['code' => '441100', 'name' => 'Pendapatan Sewa Alat Berat',        'category' => 'pendapatan', 'sub_category' => 'pendapatan_usaha', 'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi', 'tax_type' => 'ppn'],
            ['code' => '441110', 'name' => 'Pendapatan Sewa — Include Operator','category' => 'pendapatan', 'sub_category' => 'pendapatan_usaha', 'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi', 'tax_type' => 'ppn'],
            ['code' => '441200', 'name' => 'Pendapatan Ritase Dump Truck',      'category' => 'pendapatan', 'sub_category' => 'pendapatan_usaha', 'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi', 'tax_type' => 'ppn'],
            ['code' => '441300', 'name' => 'Pendapatan Penjualan Material',     'category' => 'pendapatan', 'sub_category' => 'pendapatan_usaha', 'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi', 'tax_type' => 'ppn'],
            ['code' => '441400', 'name' => 'Pendapatan Borongan Pengurugan',    'category' => 'pendapatan', 'sub_category' => 'pendapatan_usaha', 'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi', 'tax_type' => 'ppn'],
            ['code' => '441900', 'name' => 'Pendapatan Lain-lain',              'category' => 'pendapatan', 'sub_category' => 'pendapatan_lain',  'normal_balance' => 'kredit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],

            // === 5 BEBAN HPP ===
            ['code' => '551100', 'name' => 'Beban BBM Solar — Lapangan',        'category' => 'beban', 'sub_category' => 'beban_hpp', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '551200', 'name' => 'Beban Premi & Uang Jalan',          'category' => 'beban', 'sub_category' => 'beban_hpp', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '551300', 'name' => 'Beban HPP Material Alam',           'category' => 'beban', 'sub_category' => 'beban_hpp', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '551400', 'name' => 'Beban Maintenance & Suku Cadang',   'category' => 'beban', 'sub_category' => 'beban_hpp', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '551500', 'name' => 'Beban Mobilisasi & Demobilisasi',   'category' => 'beban', 'sub_category' => 'beban_hpp', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '551600', 'name' => 'Beban Subkontraktor',               'category' => 'beban', 'sub_category' => 'beban_hpp', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'pph_23'],

            // === 5 BEBAN OPERASIONAL ===
            ['code' => '552100', 'name' => 'Beban Penyusutan',                  'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'non_kas', 'tax_type' => 'non_pajak'],
            ['code' => '552200', 'name' => 'Beban Gaji & Tunjangan',            'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'pph_21'],
            ['code' => '552300', 'name' => 'Beban Asuransi Armada',             'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '552400', 'name' => 'Beban Sewa Kantor / Lahan Parkir',  'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'pph_23'],
            ['code' => '552500', 'name' => 'Beban Administrasi & Umum',        'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '552600', 'name' => 'Beban Pajak & Perizinan',          'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '552700', 'name' => 'Beban Bunga Leasing / Bank',       'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],
            ['code' => '552800', 'name' => 'Beban Kerugian Piutang',           'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'non_kas', 'tax_type' => 'non_pajak'],
            ['code' => '553000', 'name' => 'Beban Lain-lain',                  'category' => 'beban', 'sub_category' => 'beban_operasional', 'normal_balance' => 'debit', 'cash_flow_category' => 'operasi', 'tax_type' => 'non_pajak'],

            // === 9 AKUN PENUTUP ===
            ['code' => '959000', 'name' => 'Ikhtisar Laba Rugi',                'category' => 'penutup', 'sub_category' => 'penutup', 'normal_balance' => 'kredit', 'cash_flow_category' => 'non_kas', 'tax_type' => 'non_pajak'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function businessUnits(): array
    {
        return [
            ['code' => 'RENT', 'name' => 'Rental Alat Berat',       'description' => 'Sewa excavator, bulldozer, wheel loader (per jam / bulanan)', 'color' => '#3B82F6'],
            ['code' => 'ARMD', 'name' => 'Jasa Kontrak Armada',     'description' => 'Dump truck per ritase / sewa bulanan',                       'color' => '#F59E0B'],
            ['code' => 'MATL', 'name' => 'Penjualan Material Alam', 'description' => 'Pasir, batu, sirtu, tanah (per m³ / per truk)',              'color' => '#10B981'],
            ['code' => 'BONG', 'name' => 'Borongan Pengurugan',     'description' => 'Borongan & land clearing (per m³ padat / m² lahan)',         'color' => '#EF4444'],
            ['code' => 'UMUM', 'name' => 'Umum / Non-Spesifik',     'description' => 'Transaksi yang tidak terkait lini bisnis spesifik',          'color' => '#6B7280'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function materials(): array
    {
        return [
            ['code' => 'MAT-001', 'name' => 'Tanah Urug',                'harga_per_satuan' => 65000,  'satuan' => 'm3'],
            ['code' => 'MAT-002', 'name' => 'Sirtu',                     'harga_per_satuan' => 110000, 'satuan' => 'm3'],
            ['code' => 'MAT-003', 'name' => 'Pasir Urug',                'harga_per_satuan' => 125000, 'satuan' => 'm3'],
            ['code' => 'MAT-004', 'name' => 'Pasir Cor / Pasang',        'harga_per_satuan' => 165000, 'satuan' => 'm3'],
            ['code' => 'MAT-005', 'name' => 'Batu Belah',                'harga_per_satuan' => 175000, 'satuan' => 'm3'],
            ['code' => 'MAT-006', 'name' => 'Batu Split',                'harga_per_satuan' => 185000, 'satuan' => 'm3'],
            ['code' => 'MAT-007', 'name' => 'Limestone / Base Course',   'harga_per_satuan' => 95000,  'satuan' => 'm3'],
        ];
    }
}
