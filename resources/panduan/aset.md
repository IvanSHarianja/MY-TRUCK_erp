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

> **Metode penyusutan terkunci** setelah aset punya jurnal penyusutan. Untuk mengubahnya, jurnal penyusutan terkait harus di-void terlebih dahulu.

### Target jam bulanan

Dipakai widget utilisasi di Dashboard Operasional. Kosongkan bila belum ada target — sistem memakai perkiraan otomatis.

### Pemeliharaan (maintenance)

Buka aset → tab pemeliharaan → **Catat Pemeliharaan**. Isi **Biaya Jasa / Dibayar Tunai**, bengkel, HM saat service, dan jadwal service berikutnya.

Sparepart yang diambil dari gudang diisi di bagian **Sparepart dari Stok** — stok otomatis
berkurang dan nilainya dihitung dari harga pokok rata-rata (MAC). Lihat
[Material: Stok, Pembelian & Penjualan](#material).

Seluruh biaya (jasa + sparepart) otomatis dijurnal sebagai beban dan tercatat di laporan **Biaya per Unit**.
