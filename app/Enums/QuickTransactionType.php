<?php

namespace App\Enums;

/**
 * Tipe transaksi cepat di halaman "Transaksi & Beban Terpadu".
 *
 * Setiap case mendefinisikan:
 * - label() — teks dropdown
 * - debitAccountCode() / creditAccountCode() — sisi yang FIXED (akun beban/modal/dll);
 *   sisi lawan diambil dari counterAccount yang user pilih di form
 * - allowedMethods() — metode pembayaran yang valid (kas/bank/utang/nonkas)
 * - isPenyusutan() — true berarti credit di-resolve dari pilihan kategori akumulasi
 *
 * Kategori COA mengacu pada CompanyTemplateService my-truck (6-digit hierarchical).
 */
enum QuickTransactionType: string
{
    // === BEBAN OPERASIONAL & HPP ===
    case BebanSolar       = 'beban_solar';
    case BebanGaji        = 'beban_gaji';
    case BebanSparepart   = 'beban_sparepart';
    case BebanMob         = 'beban_mob';
    case BebanSubkon      = 'beban_subkon';
    case BebanRetribusi   = 'beban_retribusi';
    case BebanKantor      = 'beban_kantor';
    case BebanPenyusutan  = 'beban_penyusutan';

    // === PENDAPATAN & MODAL ===
    case PendapatanLain   = 'pendapatan_lain';
    case BayarUtang       = 'bayar_utang';
    case SetorModal       = 'setor_modal';
    case Prive            = 'prive';

    public function label(): string
    {
        return match ($this) {
            self::BebanSolar      => 'Beban Solar / BBM',
            self::BebanGaji       => 'Beban Gaji & Tunjangan',
            self::BebanSparepart  => 'Beban Maintenance & Sparepart',
            self::BebanMob        => 'Beban Mobilisasi / Demobilisasi',
            self::BebanSubkon     => 'Beban Subkontraktor',
            self::BebanRetribusi  => 'Beban Pajak & Perizinan',
            self::BebanKantor     => 'Beban Administrasi & Umum',
            self::BebanPenyusutan => 'Beban Penyusutan (penyesuaian)',
            self::PendapatanLain  => 'Pendapatan Lain-lain (diterima)',
            self::BayarUtang      => 'Pembayaran Utang Usaha',
            self::SetorModal      => 'Setoran Modal Pemilik',
            self::Prive           => 'Pengambilan Pribadi (Prive)',
        };
    }

    /** Kode akun sisi FIXED (selalu sama). null = sisi fixed-nya adalah counterAccount user. */
    public function fixedAccountCode(): ?string
    {
        return match ($this) {
            self::BebanSolar      => '551100',
            self::BebanGaji       => '552200',
            self::BebanSparepart  => '551400',
            self::BebanMob        => '551500',
            self::BebanSubkon     => '551600',
            self::BebanRetribusi  => '552600',
            self::BebanKantor     => '552500',
            self::BebanPenyusutan => '552100',
            self::PendapatanLain  => '441900',
            self::BayarUtang      => '221100',
            self::SetorModal      => '331100',
            self::Prive           => '331200',
        };
    }

    /**
     * Role akun sisi FIXED (Sprint 2.5). Dipakai untuk lookup akun tanpa
     * tergantung kode standar MY-TRUCK. Tenant yang pakai kode custom
     * (misal '300000-01' untuk modal) tinggal set role = equity_modal.
     */
    public function fixedRole(): AccountRole
    {
        return match ($this) {
            self::BebanSolar      => AccountRole::CogsBbm,
            self::BebanGaji       => AccountRole::OpexGaji,
            self::BebanSparepart  => AccountRole::CogsMaintenance,
            self::BebanMob        => AccountRole::CogsMobilisasi,
            self::BebanSubkon     => AccountRole::CogsSubkontraktor,
            self::BebanRetribusi  => AccountRole::OpexPajakPerizinan,
            self::BebanKantor     => AccountRole::OpexAdmin,
            self::BebanPenyusutan => AccountRole::OpexPenyusutan,
            self::PendapatanLain  => AccountRole::RevenueLain,
            self::BayarUtang      => AccountRole::PayableVendor,
            self::SetorModal      => AccountRole::EquityModal,
            self::Prive           => AccountRole::EquityPrive,
        };
    }

    /**
     * Sisi mana fixedAccount berada di jurnal:
     * - 'debit'  → fixedAccount di Dr, counterAccount di Cr (beban, prive)
     * - 'kredit' → fixedAccount di Cr, counterAccount di Dr (pendapatan, setoran modal)
     * - 'kredit_bayar' → fixedAccount (utang) di Dr, counterAccount di Cr (bayar utang)
     */
    public function fixedSide(): string
    {
        return match ($this) {
            self::BebanSolar, self::BebanGaji, self::BebanSparepart, self::BebanMob,
            self::BebanSubkon, self::BebanRetribusi, self::BebanKantor,
            self::BebanPenyusutan, self::Prive       => 'debit',
            self::PendapatanLain, self::SetorModal   => 'kredit',
            self::BayarUtang                         => 'debit', // 221100 di Dr (mengurangi utang)
        };
    }

    /**
     * Metode pembayaran yang diizinkan.
     * - kas/bank → counterAccount = akun kas/bank
     * - utang    → counterAccount = akun utang vendor
     * - nonkas   → counterAccount = akun akumulasi penyusutan (untuk penyusutan)
     *
     * @return array<int, string>
     */
    public function allowedMethods(): array
    {
        return match ($this) {
            self::BebanSolar, self::BebanSparepart, self::BebanMob,
            self::BebanSubkon, self::BebanKantor    => ['kas', 'bank', 'utang'],

            self::BebanGaji, self::BebanRetribusi,
            self::PendapatanLain, self::BayarUtang,
            self::SetorModal, self::Prive           => ['kas', 'bank'],

            self::BebanPenyusutan                    => ['nonkas'],
        };
    }

    public function isPenyusutan(): bool
    {
        return $this === self::BebanPenyusutan;
    }

    /**
     * Untuk penyusutan: 3 kategori akumulasi yang valid (sesuai seed COA).
     * @return array<string, string> code => label
     */
    public static function akumulasiPenyusutanOptions(): array
    {
        return [
            '112105' => 'Akumulasi Penyusutan Armada',
            '112115' => 'Akumulasi Penyusutan Peralatan Kantor',
            '112125' => 'Akumulasi Penyusutan Kendaraan Operasional',
        ];
    }

    /**
     * Apakah tipe ini terkait Beban (untuk filter alokasi lini bisnis).
     */
    public function isBeban(): bool
    {
        return str_starts_with($this->value, 'beban_');
    }

    /**
     * Apakah tipe ini termasuk Beban HPP (Harga Pokok Produksi) —
     * beban yang WAJIB dialokasikan ke lini bisnis operasional
     * (RENT/ARMD/MATL/BONG), tidak boleh nyasar ke UMUM.
     *
     * Mengapa: HPP masuk ke Laba Kotor per lini. Kalau alokasinya ke UMUM,
     * Income Statement Matrix per lini bisnis jadi tidak akurat — lini
     * yang sesungguhnya menanggung biaya kelihatan lebih untung dari kenyataan.
     *
     * Berbasis kode akun: seed COA memakai prefix 551 untuk beban HPP
     * dan 552 untuk beban operasional. Explicit match dipilih supaya
     * penambahan enum baru tidak silent-lolos.
     */
    public function isBebanHpp(): bool
    {
        return match ($this) {
            self::BebanSolar, self::BebanSparepart,
            self::BebanMob,   self::BebanSubkon => true,
            default                              => false,
        };
    }

    /**
     * Document type yang dipakai di JournalEntry.document_number prefix.
     */
    public function documentPrefix(): string
    {
        return match ($this) {
            self::BebanSolar, self::BebanGaji, self::BebanSparepart, self::BebanMob,
            self::BebanSubkon, self::BebanRetribusi, self::BebanKantor      => 'BBK', // bukti bank/kas keluar
            self::BebanPenyusutan                                            => 'JM',  // jurnal memorial
            self::PendapatanLain, self::SetorModal                           => 'BKM', // bukti kas masuk
            self::BayarUtang, self::Prive                                    => 'BBK',
        };
    }
}
