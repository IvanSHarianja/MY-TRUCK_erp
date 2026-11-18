Daftar unit dump truck, alat berat, kendaraan operasional, dan peralatan kantor — beserta harga beli dan cara penyusutannya.

### Import dari Excel

Klik **Import Excel** di halaman Armada & Alat Berat, unggah daftar aset perusahaan, lalu klik **Baca & Pratinjau**. Data **belum tersimpan** sampai Anda menekan **Import N aset** di layar pratinjau.

- Kolom dikenali dari **judulnya**: Kode / No Urut, Nama (atau Keterangan), Nopol, Tahun atau Tanggal Beli, Harga Beli, Jenis, Umur. Satu sheet boleh berisi beberapa tabel (mis. *Daftar Kendaraan* lalu *Daftar Alat Berat*).
- Data yang kurang **tetap diimpor** dan ditandai:

| Kondisi di Excel | Diisi sistem |
|---|---|
| Harga beli kosong | Rp 0 + tanda **Perlu dilengkapi** |
| Hanya tahun | Tanggal beli **1 Juli** tahun tersebut (perkiraan) |
| Tahun juga kosong | Tanggal hari ini + tanda **Perlu dilengkapi** |
| Tanpa kode | Kode otomatis: DT-001, EXC-001, DZR-001, ALT-001 … |
| Tanpa jenis | Ditebak dari nama (Dump → Dump Truck, Excavator/PC → Excavator, Dozer → Bulldozer) |
| Keterangan "dijual" | Status **Non-Aktif** |

- Semua aset hasil import memakai **garis lurus 8 tahun**. Ubah per aset bila perlu, sebelum ada jurnal penyusutan.
- Kode, nopol, atau (untuk unit tanpa keduanya) nama yang **sudah ada dilewati**, jadi import aman diulang.
- Setelah import, nyalakan filter **Perlu dilengkapi** di daftar aset. Tanda hilang sendiri begitu harga/tanggal beli diisi lewat **Edit**.

> Import hanya mengisi **data unit**. Nilai buku aset lama (akumulasi penyusutan sebelum memakai MY-TRUCK) dicatat lewat jurnal **Saldo Awal** — lihat [Pengaturan Awal](#pengaturan-awal).

### Metode penyusutan

| Metode | Kapan dipakai | Kapan dijurnal |
|---|---|---|
| **Garis Lurus (Bulanan)** | Aset yang nilainya turun seiring waktu (kantor, kendaraan) | Otomatis tiap tanggal 1 untuk bulan sebelumnya |
| **Per Jam Kerja** | Alat berat yang disewakan per jam | Setiap input jam kerja di Rental Alat Berat |
| **Per Rit** | Dump truck yang ditagih per rit | Setiap input ritase di Kontrak Armada |
| **Per Hari Kerja** | Unit yang dihitung per hari kerja | Otomatis tiap bulan |

Isi **umur ekonomis** sesuai metode (bulan / jam / rit / hari), **harga beli**, dan **nilai sisa**. Penyusutan berhenti otomatis saat nilai buku mencapai nilai sisa.

> **Data penentu penyusutan terkunci** setelah aset punya jurnal penyusutan: metode, jenis aset, harga beli, nilai sisa, tanggal beli, umur ekonomis, dan akumulasi awal. Untuk mengubahnya, jurnal penyusutan terkait harus di-void terlebih dahulu.

### Aset lama: Akumulasi Penyusutan Awal

Untuk aset yang dibeli **sebelum** memakai MY-TRUCK, isi **Akumulasi Penyusutan Awal** — total penyusutan sampai akhir bulan lalu. Klik ikon kalkulator untuk perkiraan garis lurus otomatis dari tanggal beli & umur ekonomis.

- Tanpa ini, aset lama tampil dengan nilai buku penuh: truk 2015 berumur 8 tahun tidak pernah disusutkan (umurnya sudah habis) tetapi nilainya tetap 100%.
- Dengan akumulasi awal, MY-TRUCK hanya menyusutkan **sisa** nilainya, dan aset yang umurnya habis bernilai buku 0.
- Import Excel mengisi perkiraan ini otomatis.
- Saldo di buku besar tetap dicatat sekali lewat jurnal **Saldo Awal**: Dr Aset Tetap (total harga beli) / Cr Akumulasi Penyusutan (total akumulasi awal) / Cr Modal (selisihnya).

### Target jam bulanan

Dipakai widget utilisasi di Dashboard Operasional. Kosongkan bila belum ada target — sistem memakai perkiraan otomatis.

### Pemeliharaan (maintenance)

Buka aset → tab pemeliharaan → **Catat Pemeliharaan**. Isi **Biaya Jasa / Dibayar Tunai**, bengkel, HM saat service, dan jadwal service berikutnya.

Sparepart yang diambil dari gudang diisi di bagian **Sparepart dari Stok** — stok otomatis
berkurang dan nilainya dihitung dari harga pokok rata-rata (MAC). Lihat
[Material: Stok, Pembelian & Penjualan](#material).

Seluruh biaya (jasa + sparepart) otomatis dijurnal sebagai beban dan tercatat di laporan **Biaya per Unit**.

Kolom **Bengkel / Vendor** di tabel riwayat menampilkan nama vendor yang dipilih (beserta teleponnya); servis tanpa vendor ditulis *Tanpa vendor*. Gunakan filter **Bengkel / Vendor** untuk melihat semua servis di satu bengkel. Nama vendor juga ikut di keterangan jurnal pembayaran, sehingga terbaca di **Buku Besar** akun kas.
