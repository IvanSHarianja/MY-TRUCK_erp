<?php

namespace App\Enums;

/**
 * AccountRole — fungsional role tiap akun COA.
 *
 * TUJUAN:
 * Menggantikan hardcoded code (111100, 331100, dll) di service layer.
 * User bebas pakai kode akun apapun; service query berdasarkan role.
 *
 * PRINSIP:
 * - Satu akun = maksimal satu role (bukan multi-tag).
 * - Role bersifat SEMANTIK (peran akuntansi), bukan operasional (business unit).
 * - Beberapa role mengizinkan banyak akun (mis. 'cash' bisa dipakai banyak
 *   sub-akun kas/bank berbeda). Yang lain tunggal (mis. 'equity_laba_berjalan').
 *
 * MAPPING KE STANDAR COA MY-TRUCK:
 * Lihat CompanyTemplateService::accounts() untuk assignment default per code.
 */
enum AccountRole: string
{
    // === ASET LANCAR ===
    case Cash                    = 'cash';                    // Kas & Bank utama (111100)
    case CashPetty               = 'cash_petty';              // Kas Kecil Lapangan (111110)
    case ReceivableUsaha         = 'receivable_usaha';        // Piutang Usaha (111200)
    case ReceivableRetensi       = 'receivable_retensi';      // Piutang Retensi (111210)
    case InventorySolar          = 'inventory_solar';         // Persediaan Solar Depo (111220)
    case PrepaidAsuransi         = 'prepaid_asuransi';        // Asuransi Dibayar Dimuka (111230)
    case Supplies                = 'supplies';                // Perlengkapan Lapangan (111240)
    case PrepaidOperational      = 'prepaid_operational';     // Uang Muka Operasional (111250)

    // === ASET TETAP ===
    case FixedAssetArmada        = 'fixed_asset_armada';      // 112100
    case AkumulasiArmada         = 'akumulasi_armada';        // 112105
    case FixedAssetKantor        = 'fixed_asset_kantor';      // 112110
    case AkumulasiKantor         = 'akumulasi_kantor';        // 112115
    case FixedAssetKendaraan     = 'fixed_asset_kendaraan';   // 112120
    case AkumulasiKendaraan      = 'akumulasi_kendaraan';     // 112125
    case PrepaidFixedAsset       = 'prepaid_fixed_asset';     // 113100

    // === KEWAJIBAN LANCAR ===
    case PayableVendor           = 'payable_vendor';          // 221100
    case PayableKuari            = 'payable_kuari';           // 221110
    case PayableGaji             = 'payable_gaji';            // 221120
    case PayablePpn              = 'payable_ppn';             // 221130
    case PayablePph              = 'payable_pph';             // 221140
    case PayableLeasingPendek    = 'payable_leasing_pendek';  // 221150
    case PayableLainPendek       = 'payable_lain_pendek';     // 221160
    case UangMukaProyek          = 'uang_muka_proyek';        // 221170

    // === KEWAJIBAN PANJANG ===
    case PayableLeasing          = 'payable_leasing';         // 222100
    case PayableBank             = 'payable_bank';            // 222110
    case PayablePemegangSaham    = 'payable_pemegang_saham';  // 222120
    case PayableLainPanjang      = 'payable_lain_panjang';    // 222130

    // === EKUITAS ===
    case EquityModal             = 'equity_modal';            // 331100
    case EquityPrive             = 'equity_prive';            // 331200
    case EquityLabaDitahan       = 'equity_laba_ditahan';     // 331300
    case EquityLabaBerjalan      = 'equity_laba_berjalan';    // 331400

    // === PENDAPATAN ===
    case RevenueRent             = 'revenue_rent';            // 441100 Sewa Alat
    case RevenueRentOperator     = 'revenue_rent_operator';   // 441110 Sewa Include Operator
    case RevenueArmd             = 'revenue_armd';            // 441200 Ritase
    case RevenueMatl             = 'revenue_matl';            // 441300 Material
    case RevenueBong             = 'revenue_bong';            // 441400 Borongan
    case RevenueLain             = 'revenue_lain';            // 441900

    // === BEBAN HPP ===
    case CogsBbm                 = 'cogs_bbm';                // 551100
    case CogsPremiUangJalan      = 'cogs_premi_uang_jalan';   // 551200
    case CogsMaterial            = 'cogs_material';           // 551300 HPP Material
    case CogsMaintenance         = 'cogs_maintenance';        // 551400
    case CogsMobilisasi          = 'cogs_mobilisasi';         // 551500
    case CogsSubkontraktor       = 'cogs_subkontraktor';      // 551600

    // === BEBAN OPERASIONAL ===
    case OpexPenyusutan          = 'opex_penyusutan';         // 552100
    case OpexGaji                = 'opex_gaji';               // 552200
    case OpexAsuransiArmada      = 'opex_asuransi_armada';    // 552300
    case OpexSewaKantor          = 'opex_sewa_kantor';        // 552400
    case OpexAdmin               = 'opex_admin';              // 552500
    case OpexPajakPerizinan      = 'opex_pajak_perizinan';    // 552600
    case OpexBunga               = 'opex_bunga';              // 552700
    case OpexKerugianPiutang     = 'opex_kerugian_piutang';   // 552800
    case OpexLain                = 'opex_lain';               // 553000

    // === PENUTUP ===
    case ClosingIkhtisar         = 'closing_ikhtisar';        // 959000

    /**
     * Label human-readable untuk UI (Filament Select).
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash                 => 'Kas & Bank Utama',
            self::CashPetty            => 'Kas Kecil Lapangan',
            self::ReceivableUsaha      => 'Piutang Usaha',
            self::ReceivableRetensi    => 'Piutang Retensi',
            self::InventorySolar       => 'Persediaan Solar (Depo)',
            self::PrepaidAsuransi      => 'Asuransi Dibayar Dimuka',
            self::Supplies             => 'Perlengkapan Lapangan',
            self::PrepaidOperational   => 'Uang Muka Operasional',
            self::FixedAssetArmada     => 'Aset Tetap — Armada & Alat Berat',
            self::AkumulasiArmada      => 'Akumulasi Penyusutan Armada',
            self::FixedAssetKantor     => 'Aset Tetap — Peralatan Kantor',
            self::AkumulasiKantor      => 'Akumulasi Penyusutan Peralatan',
            self::FixedAssetKendaraan  => 'Aset Tetap — Kendaraan Operasional',
            self::AkumulasiKendaraan   => 'Akumulasi Penyusutan Kendaraan',
            self::PrepaidFixedAsset    => 'Uang Muka Pembelian Aset',
            self::PayableVendor        => 'Utang Usaha Vendor',
            self::PayableKuari         => 'Utang Usaha Kuari',
            self::PayableGaji          => 'Utang Gaji Karyawan',
            self::PayablePpn           => 'Utang PPN',
            self::PayablePph           => 'Utang PPh 21 & 23',
            self::PayableLeasingPendek => 'Utang Leasing < 1 tahun',
            self::PayableLainPendek    => 'Utang Lain Lancar',
            self::UangMukaProyek       => 'Uang Muka Proyek Diterima',
            self::PayableLeasing       => 'Utang Leasing Panjang',
            self::PayableBank          => 'Utang Bank',
            self::PayablePemegangSaham => 'Utang Pemegang Saham',
            self::PayableLainPanjang   => 'Utang Jangka Panjang Lain',
            self::EquityModal          => 'Modal Pemilik / Disetor',
            self::EquityPrive          => 'Prive / Pengambilan Modal',
            self::EquityLabaDitahan    => 'Laba Ditahan',
            self::EquityLabaBerjalan   => 'Laba Tahun Berjalan',
            self::RevenueRent          => 'Pendapatan Sewa Alat Berat (RENT)',
            self::RevenueRentOperator  => 'Pendapatan Sewa Include Operator',
            self::RevenueArmd          => 'Pendapatan Ritase Dump Truck (ARMD)',
            self::RevenueMatl          => 'Pendapatan Penjualan Material (MATL)',
            self::RevenueBong          => 'Pendapatan Borongan Pengurugan (BONG)',
            self::RevenueLain          => 'Pendapatan Lain-lain',
            self::CogsBbm              => 'Beban BBM Solar',
            self::CogsPremiUangJalan   => 'Beban Premi & Uang Jalan',
            self::CogsMaterial         => 'Beban HPP Material',
            self::CogsMaintenance      => 'Beban Maintenance & Sparepart',
            self::CogsMobilisasi       => 'Beban Mobilisasi/Demobilisasi',
            self::CogsSubkontraktor    => 'Beban Subkontraktor',
            self::OpexPenyusutan       => 'Beban Penyusutan',
            self::OpexGaji             => 'Beban Gaji & Tunjangan',
            self::OpexAsuransiArmada   => 'Beban Asuransi Armada',
            self::OpexSewaKantor       => 'Beban Sewa Kantor / Lahan',
            self::OpexAdmin            => 'Beban Administrasi & Umum',
            self::OpexPajakPerizinan   => 'Beban Pajak & Perizinan',
            self::OpexBunga            => 'Beban Bunga Leasing / Bank',
            self::OpexKerugianPiutang  => 'Beban Kerugian Piutang',
            self::OpexLain             => 'Beban Lain-lain',
            self::ClosingIkhtisar      => 'Ikhtisar Laba Rugi',
        };
    }

    /**
     * Group untuk Select dropdown — memudahkan user pilih role.
     */
    public function group(): string
    {
        return match (true) {
            in_array($this, [
                self::Cash, self::CashPetty, self::ReceivableUsaha, self::ReceivableRetensi,
                self::InventorySolar, self::PrepaidAsuransi, self::Supplies, self::PrepaidOperational,
            ], true) => '1. Aset Lancar',

            in_array($this, [
                self::FixedAssetArmada, self::AkumulasiArmada, self::FixedAssetKantor,
                self::AkumulasiKantor, self::FixedAssetKendaraan, self::AkumulasiKendaraan,
                self::PrepaidFixedAsset,
            ], true) => '2. Aset Tetap',

            in_array($this, [
                self::PayableVendor, self::PayableKuari, self::PayableGaji, self::PayablePpn,
                self::PayablePph, self::PayableLeasingPendek, self::PayableLainPendek, self::UangMukaProyek,
            ], true) => '3. Kewajiban Lancar',

            in_array($this, [
                self::PayableLeasing, self::PayableBank, self::PayablePemegangSaham, self::PayableLainPanjang,
            ], true) => '4. Kewajiban Panjang',

            in_array($this, [
                self::EquityModal, self::EquityPrive, self::EquityLabaDitahan, self::EquityLabaBerjalan,
            ], true) => '5. Ekuitas',

            in_array($this, [
                self::RevenueRent, self::RevenueRentOperator, self::RevenueArmd,
                self::RevenueMatl, self::RevenueBong, self::RevenueLain,
            ], true) => '6. Pendapatan',

            in_array($this, [
                self::CogsBbm, self::CogsPremiUangJalan, self::CogsMaterial,
                self::CogsMaintenance, self::CogsMobilisasi, self::CogsSubkontraktor,
            ], true) => '7. Beban HPP',

            in_array($this, [
                self::OpexPenyusutan, self::OpexGaji, self::OpexAsuransiArmada, self::OpexSewaKantor,
                self::OpexAdmin, self::OpexPajakPerizinan, self::OpexBunga,
                self::OpexKerugianPiutang, self::OpexLain,
            ], true) => '8. Beban Operasional',

            default => '9. Lainnya',
        };
    }

    /**
     * Mapping code standar MY-TRUCK → role.
     * Dipakai untuk backfill data migration + seed default.
     *
     * @return array<string, string> code → role value
     */
    public static function standardCodeMapping(): array
    {
        return [
            // Aset Lancar
            '111100' => self::Cash->value,
            '111110' => self::CashPetty->value,
            '111200' => self::ReceivableUsaha->value,
            '111210' => self::ReceivableRetensi->value,
            '111220' => self::InventorySolar->value,
            '111230' => self::PrepaidAsuransi->value,
            '111240' => self::Supplies->value,
            '111250' => self::PrepaidOperational->value,
            // Aset Tetap
            '112100' => self::FixedAssetArmada->value,
            '112105' => self::AkumulasiArmada->value,
            '112110' => self::FixedAssetKantor->value,
            '112115' => self::AkumulasiKantor->value,
            '112120' => self::FixedAssetKendaraan->value,
            '112125' => self::AkumulasiKendaraan->value,
            '113100' => self::PrepaidFixedAsset->value,
            // Kewajiban Lancar
            '221100' => self::PayableVendor->value,
            '221110' => self::PayableKuari->value,
            '221120' => self::PayableGaji->value,
            '221130' => self::PayablePpn->value,
            '221140' => self::PayablePph->value,
            '221150' => self::PayableLeasingPendek->value,
            '221160' => self::PayableLainPendek->value,
            '221170' => self::UangMukaProyek->value,
            // Kewajiban Panjang
            '222100' => self::PayableLeasing->value,
            '222110' => self::PayableBank->value,
            '222120' => self::PayablePemegangSaham->value,
            '222130' => self::PayableLainPanjang->value,
            // Ekuitas
            '331100' => self::EquityModal->value,
            '331200' => self::EquityPrive->value,
            '331300' => self::EquityLabaDitahan->value,
            '331400' => self::EquityLabaBerjalan->value,
            // Pendapatan
            '441100' => self::RevenueRent->value,
            '441110' => self::RevenueRentOperator->value,
            '441200' => self::RevenueArmd->value,
            '441300' => self::RevenueMatl->value,
            '441400' => self::RevenueBong->value,
            '441900' => self::RevenueLain->value,
            // Beban HPP
            '551100' => self::CogsBbm->value,
            '551200' => self::CogsPremiUangJalan->value,
            '551300' => self::CogsMaterial->value,
            '551400' => self::CogsMaintenance->value,
            '551500' => self::CogsMobilisasi->value,
            '551600' => self::CogsSubkontraktor->value,
            // Beban Operasional
            '552100' => self::OpexPenyusutan->value,
            '552200' => self::OpexGaji->value,
            '552300' => self::OpexAsuransiArmada->value,
            '552400' => self::OpexSewaKantor->value,
            '552500' => self::OpexAdmin->value,
            '552600' => self::OpexPajakPerizinan->value,
            '552700' => self::OpexBunga->value,
            '552800' => self::OpexKerugianPiutang->value,
            '553000' => self::OpexLain->value,
            // Penutup
            '959000' => self::ClosingIkhtisar->value,
        ];
    }

    /**
     * Semua role dalam format Filament Select options (grouped).
     *
     * @return array<string, array<string, string>> grup → [value => label]
     */
    public static function optionsGrouped(): array
    {
        $grouped = [];
        foreach (self::cases() as $case) {
            $grouped[$case->group()][$case->value] = $case->label();
        }
        ksort($grouped);
        return $grouped;
    }

    /**
     * Default sub_category untuk role ini (auto-inherit di Account model saving).
     * User tidak perlu isi manual — cukup pilih role, sub_category ke-derive.
     */
    public function defaultSubCategory(): string
    {
        return match ($this) {
            // Aset Lancar
            self::Cash, self::CashPetty,
            self::ReceivableUsaha, self::ReceivableRetensi,
            self::InventorySolar, self::PrepaidAsuransi,
            self::Supplies, self::PrepaidOperational        => 'aset_lancar',

            // Aset Tetap
            self::FixedAssetArmada, self::AkumulasiArmada,
            self::FixedAssetKantor, self::AkumulasiKantor,
            self::FixedAssetKendaraan, self::AkumulasiKendaraan,
            self::PrepaidFixedAsset                          => 'aset_tetap',

            // Kewajiban Lancar
            self::PayableVendor, self::PayableKuari, self::PayableGaji,
            self::PayablePpn, self::PayablePph, self::PayableLeasingPendek,
            self::PayableLainPendek, self::UangMukaProyek    => 'kewajiban_lancar',

            // Kewajiban Panjang
            self::PayableLeasing, self::PayableBank,
            self::PayablePemegangSaham, self::PayableLainPanjang => 'kewajiban_panjang',

            // Ekuitas (semua ekuitas pakai sub_category 'ekuitas')
            self::EquityModal, self::EquityPrive,
            self::EquityLabaDitahan, self::EquityLabaBerjalan => 'ekuitas',

            // Pendapatan usaha (semua lini bisnis operasional)
            self::RevenueRent, self::RevenueRentOperator,
            self::RevenueArmd, self::RevenueMatl,
            self::RevenueBong                                 => 'pendapatan_usaha',

            // Pendapatan lain (di luar usaha utama)
            self::RevenueLain                                 => 'pendapatan_lain',

            // Beban HPP (Cost of Goods Sold)
            self::CogsBbm, self::CogsPremiUangJalan,
            self::CogsMaterial, self::CogsMaintenance,
            self::CogsMobilisasi, self::CogsSubkontraktor    => 'beban_hpp',

            // Beban Operasional
            self::OpexPenyusutan, self::OpexGaji, self::OpexAsuransiArmada,
            self::OpexSewaKantor, self::OpexAdmin, self::OpexPajakPerizinan,
            self::OpexBunga, self::OpexKerugianPiutang,
            self::OpexLain                                    => 'beban_operasional',

            // Penutup
            self::ClosingIkhtisar                             => 'penutup',
        };
    }

    /**
     * Kategori COA yang role ini termasuk (aset/kewajiban/ekuitas/pendapatan/beban/penutup).
     * Dipakai untuk filter dropdown Role dinamis by category di AccountForm.
     */
    public function categoryOf(): string
    {
        return match ($this->defaultSubCategory()) {
            'aset_lancar', 'aset_tetap'          => 'aset',
            'kewajiban_lancar', 'kewajiban_panjang' => 'kewajiban',
            'ekuitas'                             => 'ekuitas',
            'pendapatan_usaha', 'pendapatan_lain' => 'pendapatan',
            'beban_hpp', 'beban_operasional'      => 'beban',
            'penutup'                             => 'penutup',
        };
    }

    /**
     * Semua role yang applicable untuk category tertentu.
     * Dipakai AccountForm Select `role->options()` — user pilih category dulu,
     * dropdown role otomatis filtered ke role yang relevan.
     *
     * @return array<string, string> value → label (grouped-friendly)
     */
    public static function applicableRolesForCategory(?string $category): array
    {
        if (! $category) return [];

        return collect(self::cases())
            ->filter(fn (self $role) => $role->categoryOf() === $category)
            ->mapWithKeys(fn (self $role) => [$role->value => $role->label()])
            ->all();
    }

    /**
     * Default cash_flow_category untuk role ini (auto-inherit di Account model saving).
     * Konvensi: aktivitas operasi (revenue/expense/kas biasa), investasi (aset tetap),
     * pendanaan (modal + utang panjang), non-kas (penyusutan + akrual).
     */
    public function defaultCashFlow(): string
    {
        return match ($this) {
            // Investasi — aset tetap & pembeliannya
            self::FixedAssetArmada, self::AkumulasiArmada,
            self::FixedAssetKantor, self::AkumulasiKantor,
            self::FixedAssetKendaraan, self::AkumulasiKendaraan,
            self::PrepaidFixedAsset                           => 'investasi',

            // Pendanaan — modal, prive, utang panjang, angsuran leasing
            self::EquityModal, self::EquityPrive,
            self::PayableLeasing, self::PayableBank,
            self::PayablePemegangSaham, self::PayableLainPanjang,
            self::PayableLeasingPendek                        => 'pendanaan',

            // Non-kas — beban akrual & closing
            self::OpexPenyusutan, self::OpexKerugianPiutang,
            self::EquityLabaDitahan, self::EquityLabaBerjalan,
            self::ClosingIkhtisar                             => 'non_kas',

            // Sisanya: operasi (kas, piutang, utang usaha, revenue, cogs, opex biasa)
            default                                            => 'operasi',
        };
    }

    /**
     * Suggest role dari nama akun berdasarkan keyword matching.
     * Return null kalau tidak ada keyword match.
     *
     * Urutan penting: keyword LEBIH SPESIFIK (lebih panjang) diperiksa DULU
     * supaya 'kas kecil' match CashPetty sebelum 'kas' match Cash.
     *
     * Dipakai AccountForm untuk auto-fill role saat user ketik nama.
     */
    public static function suggestFromName(?string $name): ?self
    {
        if (! $name) return null;

        $normalized = mb_strtolower(trim($name));
        if ($normalized === '') return null;

        // Single keyword per entry — sort by length descending sebelum matching.
        // Kenapa: 'pendapatan sewa alat berat' (26 char) HARUS di-check sebelum
        // 'alat berat' (10 char) — supaya nama "Pendapatan Sewa Alat Berat"
        // return RevenueRent, bukan FixedAssetArmada.
        $keywordMap = [
            // === Akumulasi Penyusutan (paling spesifik) ===
            'akumulasi penyusutan armada'   => self::AkumulasiArmada,
            'akumulasi penyusutan kantor'   => self::AkumulasiKantor,
            'akumulasi penyusutan kendaraan' => self::AkumulasiKendaraan,
            'akumulasi armada'              => self::AkumulasiArmada,
            'akumulasi peralatan'           => self::AkumulasiKantor,
            'akumulasi kendaraan'           => self::AkumulasiKendaraan,

            // === Aset Tetap ===
            'aset tetap armada'             => self::FixedAssetArmada,
            'aset tetap peralatan'          => self::FixedAssetKantor,
            'aset tetap kendaraan'          => self::FixedAssetKendaraan,
            'peralatan kantor'              => self::FixedAssetKantor,
            'kendaraan operasional'         => self::FixedAssetKendaraan,
            'alat berat'                    => self::FixedAssetArmada,
            'excavator'                     => self::FixedAssetArmada,
            'bulldozer'                     => self::FixedAssetArmada,
            'wheel loader'                  => self::FixedAssetArmada,
            'dump truck'                    => self::FixedAssetArmada,

            // === Uang Muka ===
            'uang muka pembelian aset'      => self::PrepaidFixedAsset,
            'uang muka aset'                => self::PrepaidFixedAsset,
            'uang muka operasional'         => self::PrepaidOperational,
            'uang muka operator'            => self::PrepaidOperational,
            'uang muka proyek diterima'     => self::UangMukaProyek,
            'uang muka proyek'              => self::UangMukaProyek,

            // === Aset Lancar lain ===
            'asuransi dibayar'              => self::PrepaidAsuransi,
            'perlengkapan lapangan'         => self::Supplies,
            'perlengkapan kantor'           => self::Supplies,
            'persediaan solar'              => self::InventorySolar,
            'depo solar'                    => self::InventorySolar,
            'piutang usaha'                 => self::ReceivableUsaha,
            'piutang customer'              => self::ReceivableUsaha,
            'piutang retensi'               => self::ReceivableRetensi,

            // === Kas ===
            'kas kecil lapangan'            => self::CashPetty,
            'kas kecil'                     => self::CashPetty,
            'petty cash'                    => self::CashPetty,
            'kas lapangan'                  => self::CashPetty,

            // === Kewajiban ===
            'utang usaha vendor'            => self::PayableVendor,
            'utang usaha kuari'             => self::PayableKuari,
            'utang usaha'                   => self::PayableVendor,
            'utang gaji karyawan'           => self::PayableGaji,
            'utang gaji'                    => self::PayableGaji,
            'utang ppn'                     => self::PayablePpn,
            'ppn keluaran'                  => self::PayablePpn,
            'utang pph'                     => self::PayablePph,
            'pph 21'                        => self::PayablePph,
            'pph 23'                        => self::PayablePph,
            'utang leasing < 1 tahun'       => self::PayableLeasingPendek,
            'angsuran leasing'              => self::PayableLeasingPendek,
            'utang leasing'                 => self::PayableLeasing,
            'utang bank'                    => self::PayableBank,
            'utang pemegang saham'          => self::PayablePemegangSaham,
            'utang jangka panjang'          => self::PayableLainPanjang,

            // === Ekuitas ===
            'modal pemilik'                 => self::EquityModal,
            'modal disetor'                 => self::EquityModal,
            'setoran modal'                 => self::EquityModal,
            'modal awal'                    => self::EquityModal,
            'prive'                         => self::EquityPrive,
            'pengambilan pribadi'           => self::EquityPrive,
            'pengambilan modal'             => self::EquityPrive,
            'laba ditahan'                  => self::EquityLabaDitahan,
            'retained earnings'             => self::EquityLabaDitahan,
            'laba tahun berjalan'           => self::EquityLabaBerjalan,
            'laba berjalan'                 => self::EquityLabaBerjalan,
            'modal'                         => self::EquityModal, // fallback modal

            // === Pendapatan — 'pendapatan sewa alat berat' harus lebih panjang dari 'alat berat' agar menang ===
            'pendapatan sewa alat berat'    => self::RevenueRent,
            'pendapatan sewa include operator' => self::RevenueRentOperator,
            'sewa include operator'         => self::RevenueRentOperator,
            'pendapatan sewa alat'          => self::RevenueRent,
            'pendapatan sewa'               => self::RevenueRent,
            'pendapatan ritase dump truck'  => self::RevenueArmd,
            'pendapatan ritase'             => self::RevenueArmd,
            'ritase dump truck'             => self::RevenueArmd,
            'pendapatan penjualan material' => self::RevenueMatl,
            'penjualan material'            => self::RevenueMatl,
            'pendapatan material'           => self::RevenueMatl,
            'pendapatan borongan pengurugan' => self::RevenueBong,
            'pendapatan borongan'           => self::RevenueBong,
            'borongan pengurugan'           => self::RevenueBong,
            'pendapatan lain'               => self::RevenueLain,

            // === Beban HPP ===
            'beban bbm solar'               => self::CogsBbm,
            'beban solar'                   => self::CogsBbm,
            'beban bbm'                     => self::CogsBbm,
            'premi & uang jalan'            => self::CogsPremiUangJalan,
            'premi uang jalan'              => self::CogsPremiUangJalan,
            'uang jalan'                    => self::CogsPremiUangJalan,
            'beban hpp material'            => self::CogsMaterial,
            'hpp material'                  => self::CogsMaterial,
            'beban maintenance & sparepart' => self::CogsMaintenance,
            'beban maintenance'             => self::CogsMaintenance,
            'sparepart'                     => self::CogsMaintenance,
            'suku cadang'                   => self::CogsMaintenance,
            'maintenance'                   => self::CogsMaintenance,
            'beban mobilisasi'              => self::CogsMobilisasi,
            'mobilisasi'                    => self::CogsMobilisasi,
            'demobilisasi'                  => self::CogsMobilisasi,
            'beban subkontraktor'           => self::CogsSubkontraktor,
            'subkontraktor'                 => self::CogsSubkontraktor,
            'subkon'                        => self::CogsSubkontraktor,

            // === Beban Operasional ===
            'beban penyusutan'              => self::OpexPenyusutan,
            'penyusutan'                    => self::OpexPenyusutan,
            'beban gaji & tunjangan'        => self::OpexGaji,
            'beban gaji dan tunjangan'      => self::OpexGaji,
            'beban gaji'                    => self::OpexGaji,
            'gaji dan tunjangan'            => self::OpexGaji,
            'gaji & tunjangan'              => self::OpexGaji,
            'tunjangan karyawan'            => self::OpexGaji,
            'beban asuransi armada'         => self::OpexAsuransiArmada,
            'asuransi armada'               => self::OpexAsuransiArmada,
            'beban sewa kantor'             => self::OpexSewaKantor,
            'sewa kantor'                   => self::OpexSewaKantor,
            'sewa lahan'                    => self::OpexSewaKantor,
            'beban administrasi & umum'     => self::OpexAdmin,
            'beban administrasi'            => self::OpexAdmin,
            'administrasi & umum'           => self::OpexAdmin,
            'admin umum'                    => self::OpexAdmin,
            'beban pajak & perizinan'       => self::OpexPajakPerizinan,
            'beban pajak'                   => self::OpexPajakPerizinan,
            'pajak & perizinan'             => self::OpexPajakPerizinan,
            'perizinan'                     => self::OpexPajakPerizinan,
            'bunga leasing'                 => self::OpexBunga,
            'bunga bank'                    => self::OpexBunga,
            'kerugian piutang'              => self::OpexKerugianPiutang,
            'beban lain'                    => self::OpexLain,

            // === Penutup ===
            'ikhtisar laba rugi'            => self::ClosingIkhtisar,

            // === Cash (paling akhir karena keyword 'kas' & 'bank' generic) ===
            'kas dan bank'                  => self::Cash,
            'kas & bank'                    => self::Cash,
            'bank mandiri'                  => self::Cash,
            'bank bca'                      => self::Cash,
            'bank bni'                      => self::Cash,
            'bank bri'                      => self::Cash,
            'bank cimb'                     => self::Cash,
            'bank permata'                  => self::Cash,
            'kas'                           => self::Cash,
            'bank'                          => self::Cash,
        ];

        // Sort by keyword length DESC — longest keyword wins, hindari premature match
        $sortedKeys = array_keys($keywordMap);
        usort($sortedKeys, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($sortedKeys as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return $keywordMap[$keyword];
            }
        }

        return null;
    }
}
