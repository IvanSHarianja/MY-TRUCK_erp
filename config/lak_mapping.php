<?php

use App\Enums\AccountRole;

/**
 * Mapping Laporan Arus Kas Format Operasional (mirror layout Excel LAK ALBER & EXP).
 *
 * KENAPA file config, bukan tabel DB atau enum baru:
 * Kategori LAK Excel = ad-hoc, per-tenant bisa beda. Struktur config lebih murah
 * di-edit tanpa migrasi. Sub_role (cogs_supir vs cogs_uang_makan) SEBAGIAN memang
 * belum granular di COA existing — angka baris yang mapping-nya ke role sama
 * akan tampil sama (transparent — user tahu). Upgrade: tambah role/subrole
 * di AccountRole nanti kalau perlu granularity real.
 *
 * STRUKTUR per baris:
 *   - label: nama tampil (mirror kolom "MAPING" Excel)
 *   - description: deskripsi kolom kanan Excel
 *   - roles: array of AccountRole yang di-sum
 *   - business_unit_code: filter BU (null = semua BU)
 *   - direction:
 *       'in'  = kas masuk (debit akun kas) — dipakai section penerimaan
 *       'out' = kas keluar (kredit akun kas) — dipakai section pengeluaran
 *
 * QUERY LOGIC (di CashFlowLakService):
 *   Untuk setiap baris, SUM(jl.debit atau jl.kredit) di akun ber-role tersebut,
 *   join journal_entries yang menyentuh akun KAS (role=cash|cash_petty) di sisi
 *   lawan, filter periode + tenant + status=posted, exclude 'pembalik' & 'saldo_awal'.
 *
 *   Contoh baris "Piutang Alat Berat":
 *     roles = [ReceivableUsaha], BU = RENT, direction = 'in'
 *     → SUM kredit di akun receivable_usaha (piutang berkurang saat customer bayar)
 *       pada jurnal yang line lain-nya kena akun kas, BU=RENT.
 */
return [

    // ============================================================
    // SECTION 1: PENERIMAAN
    // ============================================================
    // Excel R9-R13
    'penerimaan' => [
        [
            'label'              => 'PIUTANG ALAT BERAT',
            'description'        => 'Penerimaan Piutang Jasa Alat Berat',
            'roles'              => [AccountRole::ReceivableUsaha],
            'business_unit_code' => 'RENT',
            'direction'          => 'in',
        ],
        [
            'label'              => 'PIUTANG EXPEDISI',
            'description'        => 'Penerimaan Piutang Jasa Expedisi (Dump Truck)',
            'roles'              => [AccountRole::ReceivableUsaha],
            'business_unit_code' => 'ARMD',
            'direction'          => 'in',
        ],
        [
            'label'              => 'PIUTANG MATERIAL',
            'description'        => 'Penerimaan Piutang Penjualan Material',
            'roles'              => [AccountRole::ReceivableUsaha],
            'business_unit_code' => 'MATL',
            'direction'          => 'in',
        ],
        [
            'label'              => 'PIUTANG BORONGAN',
            'description'        => 'Penerimaan Piutang Proyek Borongan',
            'roles'              => [AccountRole::ReceivableUsaha],
            'business_unit_code' => 'BONG',
            'direction'          => 'in',
        ],
        [
            'label'              => 'MODAL',
            'description'        => 'Penerimaan atas Setoran Modal',
            'roles'              => [AccountRole::EquityModal],
            'business_unit_code' => null,
            'direction'          => 'in',
        ],
        [
            'label'              => 'HUTANG PIHAK LAIN',
            'description'        => 'Penerimaan atas Hutang dari Pihak Lain',
            'roles'              => [
                AccountRole::PayableLainPendek,
                AccountRole::PayableLainPanjang,
                AccountRole::PayablePemegangSaham,
                AccountRole::PayableBank,
                AccountRole::PayableLeasing,
            ],
            'business_unit_code' => null,
            'direction'          => 'in',
        ],
        [
            'label'              => 'PENDAPATAN LAIN',
            'description'        => 'Pendapatan di luar usaha utama',
            'roles'              => [AccountRole::RevenueLain],
            'business_unit_code' => null,
            'direction'          => 'in',
        ],
    ],

    // ============================================================
    // SECTION 2: PENGELUARAN EXPEDISI (BU = ARMD)
    // ============================================================
    // Excel R16-R24
    'pengeluaran_expedisi' => [
        'business_unit_code' => 'ARMD',
        'items' => [
            [
                'label'       => 'BIAYA MATERIAL EXP',
                'description' => 'Pembelian Material',
                'roles'       => [AccountRole::CogsMaterial],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA SUPIR & UANG MAKAN EXP',
                'description' => 'Biaya Supir, Uang Jalan, Uang Makan',
                'roles'       => [AccountRole::CogsPremiUangJalan, AccountRole::OpexGaji],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA BM (BONGKAR MUAT) EXP',
                'description' => 'Biaya Tenaga Bongkar Muat',
                'roles'       => [AccountRole::CogsSubkontraktor],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA BBM EXP',
                'description' => 'Biaya BBM Solar',
                'roles'       => [AccountRole::CogsBbm],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA SPART PART & MONTIR EXP',
                'description' => 'Biaya Sparepart & Jasa Montir Maintenance',
                'roles'       => [AccountRole::CogsMaintenance],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA MOB/DEMOB EXP',
                'description' => 'Biaya Mobilisasi & Demobilisasi',
                'roles'       => [AccountRole::CogsMobilisasi],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA LAIN EXP',
                'description' => 'Biaya lain-lain Expedisi (Portal, Retribusi, dll)',
                'roles'       => [AccountRole::OpexLain, AccountRole::OpexPajakPerizinan],
                'direction'   => 'out',
            ],
        ],
    ],

    // ============================================================
    // SECTION 3: PENGELUARAN ALAT BERAT (BU = RENT)
    // ============================================================
    // Excel R29-R35
    'pengeluaran_alber' => [
        'business_unit_code' => 'RENT',
        'items' => [
            [
                'label'       => 'BIAYA GAJI OPERATOR ALBER',
                'description' => 'Biaya Gaji Basic + Time Sheet Operator',
                'roles'       => [AccountRole::OpexGaji, AccountRole::CogsPremiUangJalan],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA BBM ALBER',
                'description' => 'Biaya BBM Solar Alat Berat',
                'roles'       => [AccountRole::CogsBbm],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA SPART PART & MONTIR ALBER',
                'description' => 'Biaya Sparepart & Jasa Montir Maintenance',
                'roles'       => [AccountRole::CogsMaintenance],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA MOB/DEMOB ALBER',
                'description' => 'Biaya Mobilisasi & Demobilisasi Alat Berat',
                'roles'       => [AccountRole::CogsMobilisasi],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA LAIN ALBER',
                'description' => 'Biaya lain-lain Alat Berat',
                'roles'       => [AccountRole::OpexLain],
                'direction'   => 'out',
            ],
        ],
    ],

    // ============================================================
    // SECTION 4: PENGELUARAN MATERIAL (BU = MATL) — opsional
    // ============================================================
    'pengeluaran_material' => [
        'business_unit_code' => 'MATL',
        'items' => [
            [
                'label'       => 'BIAYA HPP MATERIAL',
                'description' => 'Harga Pokok Penjualan Material',
                'roles'       => [AccountRole::CogsMaterial],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA LAIN MATL',
                'description' => 'Biaya lain-lain Penjualan Material',
                'roles'       => [AccountRole::OpexLain],
                'direction'   => 'out',
            ],
        ],
    ],

    // ============================================================
    // SECTION 5: PENGELUARAN BORONGAN (BU = BONG) — opsional
    // ============================================================
    'pengeluaran_borongan' => [
        'business_unit_code' => 'BONG',
        'items' => [
            [
                'label'       => 'BIAYA SUBKONTRAKTOR BONG',
                'description' => 'Biaya Subkontraktor Proyek Borongan',
                'roles'       => [AccountRole::CogsSubkontraktor],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA LAIN BONG',
                'description' => 'Biaya lain-lain Borongan',
                'roles'       => [AccountRole::OpexLain],
                'direction'   => 'out',
            ],
        ],
    ],

    // ============================================================
    // SECTION 6: PENGELUARAN KANTOR (BU = null / UMUM)
    // ============================================================
    // Excel R40-R42
    'pengeluaran_kantor' => [
        'business_unit_code' => null,  // null = semua BU non-operasional
        'items' => [
            [
                'label'       => 'GAJI KANTOR',
                'description' => 'Biaya Gaji & Tunjangan Karyawan Kantor',
                'roles'       => [AccountRole::OpexGaji],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA OPS KANTOR',
                'description' => 'Biaya Operasional Kantor (ATK, Sewa, Utilitas)',
                'roles'       => [AccountRole::OpexAdmin, AccountRole::OpexSewaKantor],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA PAJAK & PERIZINAN',
                'description' => 'Pajak & Perizinan Perusahaan',
                'roles'       => [AccountRole::OpexPajakPerizinan],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BIAYA BUNGA & KEUANGAN',
                'description' => 'Bunga Leasing / Bank / Biaya Keuangan',
                'roles'       => [AccountRole::OpexBunga],
                'direction'   => 'out',
            ],
            [
                'label'       => 'BAYAR HUTANG',
                'description' => 'Pembayaran Hutang ke Pihak Lain',
                'roles'       => [
                    AccountRole::PayableLainPendek,
                    AccountRole::PayableLainPanjang,
                    AccountRole::PayablePemegangSaham,
                    AccountRole::PayableBank,
                    AccountRole::PayableLeasing,
                    AccountRole::PayableLeasingPendek,
                    AccountRole::PayableVendor,
                    AccountRole::PayableKuari,
                ],
                'direction'   => 'out',
            ],
            [
                'label'       => 'PRIVE',
                'description' => 'Pengambilan Prive Pemilik',
                'roles'       => [AccountRole::EquityPrive],
                'direction'   => 'out',
            ],
        ],
    ],
];
