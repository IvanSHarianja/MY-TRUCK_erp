Daftar Akun (Chart of Accounts / COA) adalah fondasi semua pencatatan. Setiap transaksi — otomatis maupun manual — dijurnal ke akun di sini. Kesalahan di COA langsung terbawa ke laporan, jadi luangkan waktu menyiapkannya dengan benar.

> Sebelum menambah akun, baca juga **[Paket Akun per Fitur](#coa-paket)** — daftar akun yang harus ada bersamaan agar fitur otomatis berjalan.

### Struktur kode akun (template standar)

| Awalan | Kelompok | Contoh |
|---|---|---|
| **111xxx** | Aset Lancar | 111100 Kas dan Bank, 111200 Piutang Usaha |
| **112xxx – 113xxx** | Aset Tetap | 112100 Aset Tetap Armada, 112105 Akumulasi Penyusutan Armada |
| **221xxx** | Kewajiban Lancar | 221100 Utang Usaha Vendor, 221170 Uang Muka Proyek |
| **222xxx** | Kewajiban Jangka Panjang | 222100 Utang Leasing, 222110 Utang Bank |
| **331xxx** | Ekuitas | 331100 Modal, 331200 Prive, 331300 Laba Ditahan |
| **441xxx** | Pendapatan | 441100 Sewa Alat Berat, 441200 Ritase, 441300 Material |
| **551xxx** | Beban Pokok (HPP) | 551100 BBM, 551200 Premi & Uang Jalan, 551400 Maintenance |
| **552xxx – 553xxx** | Beban Operasional | 552100 Penyusutan, 552200 Gaji, 552500 Administrasi |

Sub-akun memakai kode induk + nomor urut: **111100-01**, **111100-02**, dst.

### Akun HEADER vs sub-akun

- **HEADER** = akun induk yang punya sub-akun. Tidak bisa dipakai di jurnal — saldonya penjumlahan sub-akun.
- **Sub-akun (postable)** = akun yang dipakai transaksi.
- Begitu sebuah akun diberi sub-akun, akun itu otomatis menjadi HEADER.

### Cara menambah akun, langkah demi langkah

**Cara termudah — sub-akun dari akun yang sudah ada:** klik **+ Sub-Akun** pada baris akun induk. Form terbuka dengan kode sub-akun sudah disarankan, dan **Kategori, Sub-Kategori, Saldo Normal, Kategori Arus Kas, serta Peran otomatis diwarisi dari induk**. Anda cukup mengisi nama.

**Akun baru tanpa induk:**

1. **Kode Akun** — ikuti struktur di atas. Kode harus unik.
2. **Nama Akun** — ketik nama yang jelas. Sistem menebak Peran dari nama (mis. "Bank Mandiri" → Kas & Bank Utama). Periksa tebakannya.
3. **Kategori** — Aset / Kewajiban / Ekuitas / Pendapatan / Beban.
4. **Peran Akun (Role)** — wajib. Pilihan otomatis dibatasi sesuai kategori. Memilih peran akan mengisi Sub-Kategori dan Kategori Arus Kas yang tepat.
5. **Sub-Kategori** — menentukan posisi di laporan. Contoh: aset tetap harus **Aset Tetap**, bukan Aset Lancar.
6. **Saldo Normal** — Debit untuk aset & beban; Kredit untuk kewajiban, ekuitas, dan pendapatan. Pengecualian: akun **akumulasi penyusutan** bersaldo normal Kredit walaupun berada di kelompok aset, dan **prive** bersaldo normal Debit walaupun berada di ekuitas.
7. **Kategori Arus Kas** — Operasi / Investasi / Pendanaan / Non-Kas. Terisi otomatis dari peran.

### Peran (Role): cara sistem menemukan akun

Transaksi otomatis tidak mencari akun berdasarkan nama, tetapi berdasarkan **Peran**. Contoh: saat invoice rental terbit, sistem mencari akun berperan *Pendapatan Sewa Alat Berat (RENT)*.

- Bila tidak ada akun dengan peran tersebut, sistem mencoba **kode standar** (mis. 441100).
- Bila beberapa akun punya peran yang sama, **sistem memakai akun berkode terkecil**. Contoh: "441100-01 Sewa Excavator" dan "441100-02 Sewa Bulldozer" sama-sama berperan Pendapatan Sewa → invoice otomatis selalu masuk ke 441100-01. Invoice dari tombol **Tagih** selalu memakai akun pertama tersebut; akun lain hanya bisa dipilih manual pada invoice yang dibuat lewat **Buat Invoice Baru**. Jadi untuk pendapatan yang ditagih otomatis, cukup gunakan satu akun per peran.
- Pengecualian: peran **Kas & Bank Utama** memang dirancang untuk banyak akun — setiap rekening bank muncul di pilihan pembayaran.

### Import COA dari Excel

Template berisi 4 kolom: **Kode**, **Nama Akun**, **Kategori** (aset/kewajiban/ekuitas/pendapatan/beban), dan **Kode Parent** (opsional). Semua kesalahan ditampilkan sekaligus supaya bisa diperbaiki dalam satu kali unggah.

Setelah import, **wajib cek**:

- Akun dengan **kode standar** (111100, 441100, dst) otomatis mendapat Peran. Akun dengan **kode sendiri** belum punya Peran — buka tiap akun penting dan isi Perannya, mengacu ke [Paket Akun per Fitur](#coa-paket).
- Tanpa Peran, sub-kategori ditebak dari kategori: semua aset dianggap **Aset Lancar**, semua beban dianggap **Beban Operasional**. Perbaiki aset tetap, akumulasi penyusutan, dan beban HPP.
- Saldo normal ditentukan dari kategori. Periksa akun akumulasi penyusutan (harus Kredit) dan prive (harus Debit).

### Kesalahan umum

| Kesalahan | Akibat |
|---|---|
| Rekening bank tanpa peran Kas & Bank | Tidak muncul di pilihan pembayaran dan tidak masuk laporan Arus Kas |
| Aset tetap bersub-kategori Aset Lancar | Neraca menampilkannya di kelompok yang salah |
| Akumulasi penyusutan bersaldo normal Debit | Nilai buku aset di Neraca salah |
| Menonaktifkan akun beban yang dipakai transaksi otomatis | Jurnal otomatis gagal atau tidak lengkap (lihat [Paket Akun per Fitur](#coa-paket)) |

### Tips

- Buat satu sub-akun per rekening bank — mutasi per rekening terlihat di **Posisi Kas per Rekening** laporan Arus Kas.
- Nonaktifkan akun yang tidak dipakai lagi daripada menghapusnya, agar histori tetap utuh. Akun HEADER yang masih punya sub-akun tidak bisa dihapus.
- Kalau akun yang sudah punya transaksi ingin dipecah, gunakan **Migrasi ke Sub-Akun** — semua baris jurnal di akun induk dipindahkan ke sub-akun yang Anda pilih.
